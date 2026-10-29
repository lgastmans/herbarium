import './bootstrap';
import { Livewire } from './../../vendor/livewire/livewire/dist/livewire.esm';
import './../../vendor/power-components/livewire-powergrid/dist/powergrid'
 
// If you use Tailwind 
import './../../vendor/power-components/livewire-powergrid/dist/tailwind.css'

// If you use Bootstrap 5 
//import './../../vendor/power-components/livewire-powergrid/dist/bootstrap5.css'

import flatpickr from "flatpickr";

import 'flowbite';

// WireUI's deferred script must register its alpine:init listeners before
// Livewire starts Alpine. PowerGrid's imports above must also finish first.
if (document.readyState !== 'complete') {
    window.addEventListener('load', () => Livewire.start(), { once: true });
} else {
    Livewire.start();
}
