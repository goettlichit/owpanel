{{-- Page header of the customer pages. Expects $customer and $title. --}}
<x-page-header :title="$title" :pretitle="$customer->display_number.' · '.$customer->display_name" />
