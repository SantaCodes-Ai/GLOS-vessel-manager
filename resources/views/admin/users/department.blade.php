@extends('layouts.admin')
@section('title', 'Edit department')
@section('content')
    <h1>Edit department</h1><p>{{ $user->name }} · {{ $user->email }}</p>
    @if($errors->any())<div class="vessel-error-summary" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ route('admin.users.department.update', $user) }}" class="panel vessel-form">@csrf @method('PATCH')
        @include('shared.department-fields', ['departmentValue' => $user->department])
        <div class="vessel-toolbar"><button class="vessel-button">Save department</button><a class="vessel-link" href="{{ route('admin.users.index') }}">Cancel</a></div>
    </form>
@endsection
