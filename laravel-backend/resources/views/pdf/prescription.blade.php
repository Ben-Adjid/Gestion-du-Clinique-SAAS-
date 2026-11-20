<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #0f172a; margin: 0; }
  .clinic { font-size: 14px; font-weight: bold; color: #065f46; }
  .muted { color: #64748b; font-size: 9px; }
  .head { border-bottom: 2px solid #047857; padding-bottom: 8px; }
  .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 1px; margin: 12px 0 8px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 4px 3px; vertical-align: top; }
  .box { border: 1px solid #cbd5e1; padding: 6px; }
  .items th { text-align: left; font-size: 9px; color: #475569; border-bottom: 1px solid #cbd5e1; }
  .items td { border-bottom: 1px solid #f1f5f9; padding: 6px 3px; }
  .big { font-size: 11px; font-weight: bold; }
  .sign { margin-top: 14px; height: 56px; border: 1px dashed #94a3b8; }
  .foot { margin-top: 6px; font-size: 8px; color: #64748b; text-align: center; }
</style>
</head>
<body>
  <div class="head">
    <table>
      <tr>
        <td>
          @if ($logo)
            <img src="{{ $logo }}" alt="Logo {{ $clinic->name }}" style="height: 44px; margin-bottom: 4px;"><br>
          @endif
          <div class="clinic">{{ $clinic->name }}</div>
          <div class="muted">{{ trim(($clinic->city ?? '').' '.($clinic->country ?? '')) }}</div>
          <div class="muted">Tél. {{ $clinic->settings['phone'] ?? $clinic->admin_phone ?? '—' }}</div>
        </td>
        <td style="text-align: right;" class="muted">
          Numéro d’agrément<br><strong>{{ $licence ?: 'Non renseigné' }}</strong>
        </td>
      </tr>
    </table>
  </div>

  <div class="title">ORDONNANCE MÉDICALE</div>

  <table class="box">
    <tr>
      <td>
        <strong>Patient(e)</strong><br>
        {{ strtoupper($patient->last_name) }} {{ $patient->first_name }}<br>
        <span class="muted">Dossier {{ $patient->patient_unique_id }}</span>
      </td>
      <td style="text-align: right;">
        <strong>Date</strong><br>{{ $prescription->prescribed_at->format('d/m/Y') }}<br>
        <span class="muted">Ord. ORD-{{ str_pad((string) $prescription->id, 6, '0', STR_PAD_LEFT) }}</span>
      </td>
    </tr>
  </table>

  <table class="items" style="margin-top: 10px;">
    <thead>
      <tr><th>Médicament</th><th>Posologie</th><th>Durée</th><th style="text-align: right;">Qté</th></tr>
    </thead>
    <tbody>
      @forelse ($prescription->items as $item)
        <tr>
          <td>
            <span class="big">{{ $item->medication?->name ?? 'Médicament' }}</span><br>
            <span class="muted">{{ $item->special_instructions ?: '' }}</span>
          </td>
          <td>{{ $item->dosage_prescribed }}<br><span class="muted">{{ $item->frequency }}</span></td>
          <td>{{ $item->duration_days }} jour(s)</td>
          <td style="text-align: right;">{{ $item->quantity }}</td>
        </tr>
      @empty
        <tr><td colspan="4" class="muted">Aucun médicament prescrit.</td></tr>
      @endforelse
    </tbody>
  </table>

  @if ($prescription->notes)
    <p style="margin-top: 10px;"><strong>Conseils :</strong> {{ $prescription->notes }}</p>
  @endif

  <table style="margin-top: 16px;">
    <tr>
      <td class="muted" style="width: 55%;">
        @if ($verification)
          Code de vérification : <strong>{{ $verification }}</strong><br>
          Une pharmacie peut vérifier l’authenticité de cette ordonnance avec ce code.
        @endif
      </td>
      <td>
        <strong>{{ $doctorName }}</strong><br>
        <span class="muted">N° d’ordre : {{ $doctorLicence ?: 'non renseigné' }}</span>
        <div class="sign"></div>
        <span class="muted">Signature et cachet du médecin</span>
      </td>
    </tr>
  </table>

  <div class="foot">Document généré par Clinique+ · {{ $clinic->name }}</div>
</body>
</html>
