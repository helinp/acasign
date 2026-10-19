<datalist id="known-users">
    @foreach($knownUsers as $knownUser)
        <option value="{{ $knownUser->email }}">{{ $knownUser->signatureName() }}@if($knownUser->job_title) · {{ $knownUser->job_title }}@endif</option>
    @endforeach
</datalist>
