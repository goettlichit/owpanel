<x-card :title="__('packages.about.title')" tone="info">
    <ul class="mb-0 ps-3">
        @foreach (__('packages.about.points') as $point)
            <li>{{ $point }}</li>
        @endforeach
    </ul>
</x-card>
