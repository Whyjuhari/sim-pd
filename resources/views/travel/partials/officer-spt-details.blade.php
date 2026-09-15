<details class="card officer-spt-details" @if ($needsRevision) open @endif>
    <summary class="card-header bg-white fw-semibold">Rincian SPT</summary>
    <div class="card-body">
        @if ($canModify)
            <a class="btn btn-outline-primary mb-3" href="{{ route('travel-orders.edit', $groupParams) }}"><i
                    class="bi bi-pencil-square"></i> Edit SPT</a>
        @else
            <p class="small text-muted"><i class="bi bi-lock"></i> Data SPT dikunci setelah dikirim.</p>
        @endif
        <h2 class="h6">Pegawai yang Ditugaskan</h2>
        <ol class="officer-spt-members ps-3 mb-4">
            @foreach ($travels as $member)
                <li class="mb-2">
                    <div class="fw-semibold">{{ $member->pegawai?->nama_lengkap ?? '-' }}</div>
                    <div class="small text-muted">NIP {{ $member->pegawai?->nip ?? '-' }} ·
                        {{ $member->pegawai?->pangkat_golongan ?? '-' }}</div>
                    <div class="small text-muted">{{ $member->pegawai?->jabatan ?? '-' }}</div>
                    @if ($processComplete)
                        <div class="small mt-1">Perjalanan: <x-ui.status-pill :status="$member->status" /></div>
                    @endif
                </li>
            @endforeach
        </ol>
        <dl class="row g-2 mb-3">
            <dt class="col-12 col-md-4">Tempat Berangkat</dt>
            <dd class="col-12 col-md-8">{{ $travel->tempat_berangkat }}</dd>
            <dt class="col-12 col-md-4">Jenis Angkutan</dt>
            <dd class="col-12 col-md-8">{{ $travel->angkutan }}</dd>
            <dt class="col-12 col-md-4">Template</dt>
            <dd class="col-12 col-md-8">{{ $travel->sptTemplateLabel() }}</dd>
            @if ($travel->no_memo || $travel->tgl_memo || $travel->perihal_memo)
                <dt class="col-12 col-md-4">Memo Internal</dt>
                <dd class="col-12 col-md-8">{{ $travel->no_memo }} @if ($travel->tgl_memo)
                        · {{ $travel->tgl_memo->format('d/m/Y') }}
                    @endif
                    @if ($travel->perihal_memo)
                        <div>{{ $travel->perihal_memo }}</div>
                    @endif
                </dd>
            @endif
            @if ($travel->usesDipaTemplate())
                <dt class="col-12 col-md-4">DIPA</dt>
                <dd class="col-12 col-md-8">TA {{ $travel->dipa_fiscal_year_snapshot }} ·
                    {{ $travel->dipa_number_snapshot }} · {{ $travel->dipa_date_snapshot?->format('d/m/Y') }}</dd>
            @endif
            <dt class="col-12 col-md-4">Menimbang</dt>
            <dd class="col-12 col-md-8 officer-spt-narrative">{{ $travel->menimbang }}</dd>
            <dt class="col-12 col-md-4">Maksud Perjalanan</dt>
            <dd class="col-12 col-md-8 officer-spt-narrative">{{ $travel->maksud_perjalanan }}</dd>
            <dt class="col-12 col-md-4">Akun Anggaran</dt>
            <dd class="col-12 col-md-8">{{ $travel->akun_anggaran }}</dd>
            <dt class="col-12 col-md-4">Estimasi per Pegawai</dt>
            <dd class="col-12 col-md-8">Rp {{ number_format((float) $travel->estimasi_biaya, 0, ',', '.') }}</dd>
        </dl>
        {{-- @include('travel.partials.officer-spt-history') --}}
        @if ($canDelete)
            <form class="mt-3" method="POST" action="{{ route('travel-orders.destroy', $groupParams) }}"
                data-sim-confirm data-sim-confirm-title="Hapus SPT kolektif?"
                data-sim-confirm-text="Semua data pegawai dalam SPT ini akan dihapus. Tindakan ini tidak dapat dibatalkan."
                data-sim-confirm-button="Ya, hapus SPT" data-sim-confirm-tone="danger">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Hapus SPT</button>
            </form>
        @endif
    </div>
</details>
