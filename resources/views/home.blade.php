@extends('layouts.app')

@section('title', config('app.name'))

@section('content')
    <h1 class="text-3xl font-semibold">{{ config('app.name') }}</h1>
    <p class="mt-4 text-gray-600 dark:text-gray-400">Kommer snart.</p>
@endsection
