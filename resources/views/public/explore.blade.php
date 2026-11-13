@extends('public.layouts.app')

@section('title', 'Explore')
@section('page-class', 'page-explore')
@section('description', 'Search the collection records of Auroville Dry Herbarium by botanical name, family, collection number, collector or place.')

@section('content')
    <div class="container">
        <livewire:public-site.catalogue />
    </div>
@endsection
