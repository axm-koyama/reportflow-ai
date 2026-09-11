@if ($errors->any())
    <div class="errors" role="alert">
        <strong>Please correct the following errors:</strong>
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
