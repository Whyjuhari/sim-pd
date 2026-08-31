@props(['groupId' => null])

<div class="spt-cost-preview mt-2"
    data-spt-cost-preview
    data-preview-url="{{ route('travel-orders.cost-preview') }}"
    @if($groupId) data-spt-group-id="{{ $groupId }}" @endif>
    <button type="button" class="btn btn-outline-primary" data-spt-preview-trigger>
        <i class="bi bi-calculator"></i> Periksa Perhitungan
    </button>
    <div class="spt-cost-preview-panel mt-3" data-spt-preview-panel hidden aria-live="polite"></div>
</div>
