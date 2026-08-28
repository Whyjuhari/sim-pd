@props(['items', 'removable' => false])

<div class="evidence-list">
    @foreach ($items as $evidence)
        <div class="evidence-item" data-existing-evidence data-evidence-id="{{ $evidence->id }}"
            data-evidence-name="{{ $evidence->nama_asli }}" data-evidence-mime="{{ $evidence->mime_type }}"
            data-evidence-url="{{ route('realization-evidence.show', $evidence) }}">
            <a href="{{ route('realization-evidence.show', $evidence) }}" class="evidence-preview" target="_blank"
                rel="noopener">
                @if ($evidence->isImage())
                    <img src="{{ route('realization-evidence.show', $evidence) }}"
                        alt="Bukti {{ $evidence->nama_asli }}" loading="lazy">
                @else
                    <span class="evidence-pdf"><i class="bi bi-file-earmark-pdf-fill"></i></span>
                @endif
            </a>
            <div class="evidence-copy">
                <a href="{{ route('realization-evidence.show', $evidence) }}" target="_blank" rel="noopener"
                    class="fw-semibold text-decoration-none text-break">{{ $evidence->nama_asli }}</a>
                <small>{{ strtoupper(pathinfo($evidence->path, PATHINFO_EXTENSION)) }} ·
                    {{ number_format($evidence->ukuran / 1024, 0, ',', '.') }} KB</small>
                @if ($removable)
                    <div class="form-check mt-2">
                        <input disabled class="form-check-input" type="checkbox" name="hapus_bukti[]"
                            value="{{ $evidence->id }}" id="hapus-bukti-{{ $evidence->id }}"
                            @checked(in_array($evidence->id, array_map('intval', old('hapus_bukti', [])), true))>
                        <label class="form-check-label small text-danger" for="hapus-bukti-{{ $evidence->id }}">
                            Hapus saat dikirim ulang
                        </label>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
