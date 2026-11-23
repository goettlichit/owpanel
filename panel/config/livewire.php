<?php

/*
 * Only the settings that differ from Livewire's defaults; Livewire merges
 * them with its own configuration.
 */
return [

    // The Content Security Policy of the panel allows no eval, so Livewire
    // loads the CSP-safe build of Alpine (E-157).
    'csp_safe' => true,

];
