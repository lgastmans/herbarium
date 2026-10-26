<?php

use App\Livewire\Admin\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">

            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('admin.dashboard') }}" wire:navigate>
                        <img src="{{ asset('SimpleLeaves_04.svg') }}" width="32px"> <!-- <x-admin::application-logo class="block h-9 w-auto fill-current text-gray-800" /> -->
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">

                    <x-admin::nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </x-admin::nav-link>

                    {{-- 
                    <x-admin::nav-link :href="route('admin.plants')" :active="request()->routeIs('admin.plants')" wire:navigate>
                        {{ __('Herbarium') }}
                    </x-admin::nav-link>
                    --}}

                    <x-admin::dropdown-laravel width="36">
                        <x-slot name="trigger">
                            <button class="inline-flex items-center px-3 py-6 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                                Herbarium
                                <div class="ms-1">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            <x-admin::dropdown-link :href="route('admin.plants')" wire:navigate>
                                {{ __('Browse') }}
                            </x-admin::dropdown-link>
                            @can('import-herbarium-images')
                                <x-admin::dropdown-link :href="route('admin.herbarium.images.import')" wire:navigate>
                                    {{ __('Import Images') }}
                                </x-admin::dropdown-link>
                            @endcan
                            <x-admin::dropdown-link :href="route('admin.genus.replace')" wire:navigate>
                                {{ __('Replace Genus') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.family.replace')" wire:navigate>
                                {{ __('Replace Family') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.place.replace')" wire:navigate>
                                {{ __('Replace Place') }}
                            </x-admin::dropdown-link>
                        </x-slot>
                    </x-admin::dropdown-laravel>                    

                    <x-admin::nav-link :href="route('admin.users')" :active="request()->routeIs('admin.users')" wire:navigate>
                        {{ __('Users') }}
                    </x-admin::nav-link>

                    <x-admin::dropdown-laravel width="36">
                        <x-slot name="trigger">
                            <button class="inline-flex items-center px-3 py-6 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                                Settings
                                <div class="ms-1">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            <x-admin::dropdown-link :href="route('admin.families')" wire:navigate>
                                {{ __('Family') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.genus')" wire:navigate>
                                {{ __('Genus') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.places')" wire:navigate>
                                {{ __('Place') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.taluks')" wire:navigate>
                                {{ __('Taluk') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.districts')" wire:navigate>
                                {{ __('District') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.states')" wire:navigate>
                                {{ __('State') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.specifics')" wire:navigate>
                                {{ __('Specific') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.statuses')" wire:navigate>
                                {{ __('Status') }}
                            </x-admin::dropdown-link>
                            <x-admin::dropdown-link :href="route('admin.collectors')" wire:navigate>
                                {{ __('Collector') }}
                            </x-admin::dropdown-link>
                        </x-slot>
                    </x-admin::dropdown-laravel>                    
                </div>
            </div>

            <!-- User (Settings) Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-admin::dropdown-laravel align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-admin::dropdown-link :href="route('admin.profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-admin::dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-admin::dropdown-link>
                                {{ __('Log Out') }}
                            </x-admin::dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-admin::responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </x-admin::responsive-nav-link>
            <x-admin::responsive-nav-link :href="route('admin.plants')" :active="request()->routeIs('admin.plants')" wire:navigate>
                {{ __('Plants') }}
            </x-admin::responsive-nav-link>
            @can('import-herbarium-images')
                <x-admin::responsive-nav-link :href="route('admin.herbarium.images.import')" :active="request()->routeIs('admin.herbarium.images.import')" wire:navigate>
                    {{ __('Import Images') }}
                </x-admin::responsive-nav-link>
            @endcan
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-admin::responsive-nav-link :href="route('admin.profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-admin::responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-admin::responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-admin::responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
