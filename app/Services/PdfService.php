<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentField;
use App\Models\Setting;
use App\Models\SignatureRequest;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\PdfParser\PdfParserException;
use setasign\Fpdi\Tcpdf\Fpdi;

class PdfService
{
    /**
     * Réécrit le PDF déposé (normalisé par qpdf) en un PDF aplati lisible par FPDI.
     *
     * @throws ValidationException si le PDF ne peut pas être lu.
     */
    public function sanitize(string $sourcePath, string $targetPath): int
    {
        $readablePath = $this->normalize($sourcePath);

        try {
            $pdf = $this->newPdf();
            try {
                $pages = $pdf->setSourceFile($readablePath);
            } catch (PdfParserException) {
                throw ValidationException::withMessages([
                    'document' => 'Ce PDF ne peut pas être lu (fichier protégé par mot de passe ou endommagé). Réexportez-le en PDF standard puis réessayez.',
                ]);
            }
            throw_if($pages < 1 || $pages > 200, ValidationException::withMessages(['document' => 'Le PDF doit contenir entre 1 et 200 pages.']));
            $this->copyPages($pdf, $pages);
            $this->ensureDirectory($targetPath);
            $pdf->Output($targetPath, 'F');
        } finally {
            if ($readablePath !== $sourcePath) {
                @unlink($readablePath);
            }
        }

        return $pages;
    }

    /**
     * Convertit les PDF 1.5+ (flux d'objets et tables xref compressées, non gérés par le parseur
     * gratuit de FPDI) en structure classique, et retire le chiffrement sans mot de passe d'ouverture.
     * Retourne le chemin source tel quel si qpdf est absent ou échoue.
     */
    private function normalize(string $sourcePath): string
    {
        $normalizedPath = sys_get_temp_dir().'/'.Str::uuid().'.pdf';
        $result = Process::timeout(60)->run(['qpdf', '--decrypt', '--object-streams=disable', $sourcePath, $normalizedPath]);

        // Code 3 : qpdf a réussi avec des avertissements (PDF légèrement non conforme).
        if (in_array($result->exitCode(), [0, 3], true) && is_file($normalizedPath) && filesize($normalizedPath) > 0) {
            return $normalizedPath;
        }
        @unlink($normalizedPath);

        return $sourcePath;
    }

    /**
     * Taille de chaque page en points PDF (1 pt = 1/72 pouce), lue sur un PDF déjà assaini.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function pageSizes(string $path): array
    {
        $pdf = $this->newPdf();
        $count = $pdf->setSourceFile($path);
        $sizes = [];
        for ($page = 1; $page <= $count; $page++) {
            $size = $pdf->getTemplateSize($pdf->importPage($page));
            $sizes[] = [round($size['width'] * 72 / 25.4, 2), round($size['height'] * 72 / 25.4, 2)];
        }

        return $sizes;
    }

    public function stamp(Document $document, SignatureRequest $request, string $signatureBytes): string
    {
        $source = Storage::disk('local')->path($document->file_path);
        $target = dirname($source).'/'.Str::uuid().'.pdf';
        $signatureFile = tempnam(sys_get_temp_dir(), 'signature-').'.png';
        $qrFile = tempnam(sys_get_temp_dir(), 'qr-').'.png';
        file_put_contents($signatureFile, $signatureBytes);
        (new PngWriter)->write(new QrCode(
            data: route('verify.show', $request->verification_code),
            size: 320,
            margin: 8,
        ))->saveToFile($qrFile);

        try {
            $pdf = $this->newPdf();
            $pages = $pdf->setSourceFile($source);
            for ($page = 1; $page <= $pages; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
                if ($page === $request->page) {
                    $this->drawSignature($pdf, $size, $request, $signatureFile, $qrFile);
                }
                $this->drawFields($pdf, $size, $request->fields->where('page', $page));
            }
            $pdf->Output($target, 'F');
        } finally {
            @unlink($signatureFile);
            @unlink($qrFile);
        }

        rename($target, $source);

        return hash_file('sha256', $source);
    }

    /**
     * Copie du PDF courant avec les champs complétés du signataire (avant une signature eID, qui fige ensuite le fichier).
     */
    public function fillFields(Document $document, SignatureRequest $request): string
    {
        $pdf = $this->newPdf();
        $pages = $pdf->setSourceFile(Storage::disk('local')->path($document->file_path));
        for ($page = 1; $page <= $pages; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
            $this->drawFields($pdf, $size, $request->fields->where('page', $page));
        }

        return $pdf->Output('', 'S');
    }

    public function seal(Document $document): string
    {
        $source = Storage::disk('local')->path($document->file_path);
        $target = dirname($source).'/'.Str::uuid().'-sealed.pdf';
        $certificate = config('services.signature.certificate');
        $privateKey = config('services.signature.private_key');
        throw_unless(is_file($certificate) && is_file($privateKey), new \RuntimeException('Certificat de scellement PDF non configuré.'));

        $pdf = $this->newPdf();
        $pdf->setSignature(
            'file://'.$certificate,
            'file://'.$privateKey,
            (string) config('services.signature.private_key_password'),
            '',
            2,
            ['Name' => Setting::entityName(), 'Reason' => 'Validation interne', 'Location' => 'Réseau interne']
        );
        $pages = $pdf->setSourceFile($source);
        $this->copyPages($pdf, $pages);
        $pdf->Output($target, 'F');
        rename($target, $source);

        return hash_file('sha256', $source);
    }

    private function newPdf(): Fpdi
    {
        $pdf = new Fpdi('P', 'mm');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator(Setting::entityName());

        return $pdf;
    }

    private function copyPages(Fpdi $pdf, int $pages): void
    {
        for ($page = 1; $page <= $pages; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }
    }

    private function drawSignature(Fpdi $pdf, array $page, SignatureRequest $request, string $signatureFile, string $qrFile): void
    {
        $x = $request->position_x * $page['width'];
        $y = $request->position_y * $page['height'];
        $w = $request->width * $page['width'];
        $h = $request->height * $page['height'];
        $qr = min($h * .46, $w * .20);

        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(32, 47, 96);
        $pdf->SetLineWidth(.25);
        $pdf->RoundedRect($x, $y, $w, $h, 1.5, '1111', 'DF');
        $pdf->Image($signatureFile, $x + 2, $y + 2, max(1, $w - $qr - 6), max(1, $h * .58), 'PNG', '', '', true, 300, '', false, false, 0, true);
        $pdf->Image($qrFile, $x + $w - $qr - 2, $y + 2, $qr, $qr, 'PNG');

        $pdf->SetTextColor(32, 47, 96);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($x + 2, $y + $h * .62);
        $pdf->MultiCell($w - $qr - 6, 2.8, implode("\n", $request->stampLines(now())), 0, 'L', false);
        $pdf->SetFont('helvetica', '', 5.5);
        $pdf->SetXY($x + $w - $qr - 2, $y + $qr + 2);
        $pdf->MultiCell($qr, 2.4, 'Vérifier', 0, 'C', false);
    }

    /**
     * Imprime les valeurs des champs complétés (date, coche, texte) sur la page courante.
     *
     * @param  array{width: float, height: float}  $page
     * @param  iterable<DocumentField>  $fields
     */
    private function drawFields(Fpdi $pdf, array $page, iterable $fields): void
    {
        $pdf->SetTextColor(20, 24, 40);
        $pdf->setCellPaddings(0.6, 0, 0.6, 0);
        foreach ($fields as $field) {
            $value = $field->printableValue();
            if ($value === null) {
                continue;
            }
            $x = $field->position_x * $page['width'];
            $y = $field->position_y * $page['height'];
            $w = $field->width * $page['width'];
            $h = $field->height * $page['height'];
            $pdf->SetXY($x, $y);
            if ($field->type === 'checkbox') {
                // ZapfDingbats « 4 » = coche ✔ ; 1 mm ≈ 2,83 pt.
                $pdf->SetFont('zapfdingbats', '', max(6, min(16, $h * 2.83 * 0.85)));
                $pdf->Cell($w, $h, '4', 0, 0, 'C');
            } else {
                $pdf->SetFont('helvetica', '', max(5, min(11, $h * 2.83 * 0.7)));
                $pdf->Cell($w, $h, $value, 0, 0, 'L', false, '', 1);
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
    }
}
