<div class="text-center py-5">
    <i class="bi {{ $icon }}" style="font-size: 3rem; color: var(--color-ink-muted); opacity: .4;"></i>
    <h5 class="mt-3 mb-1">{{ $title }}</h5>
    <p class="text-muted small mb-3">{{ $description }}</p>
    @can($ctaPermission)
        <a href="{{ route($ctaRoute) }}" class="btn btn-primary btn-sm">{{ $ctaLabel }}</a>
    @endcan
</div>
