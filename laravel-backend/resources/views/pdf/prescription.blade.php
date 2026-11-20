<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Ordonnance ORD-{{ str_pad((string) $prescription->id, 6, '0', STR_PAD_LEFT) }}</title>
</head>
<body>
  @include('pdf.partials.frame')

  <div class="title">ORDONNANCE MÉDICALE</div>

  <table class="box">
    <tr>
      <td>
        <strong>Patient(e)</strong><br>
        {{ strtoupper($patient->last_name) }} {{ $patient->first_name }}<br>
        <span class="muted">Dossier {{ $patient->patient_unique_id }}</span>
      </td>
      <td class="right">
        <strong>Date</strong><br>{{ $prescription->prescribed_at->format('d/m/Y') }}<br>
        <span class="muted">N° ORD-{{ str_pad((string) $prescription->id, 6, '0', STR_PAD_LEFT) }}</span>
      </td>
    </tr>
  </table>

  <table class="items" style="margin-top: 10px;">
    <thead>
      <tr><th>Médicament</th><th>Posologie</th><th>Durée</th><th class="right">Qté</th></tr>
    </thead>
    <tbody>
      @forelse ($prescription->items as $item)
        <tr>
          <td>
            <strong>{{ $item->medication?->name ?? 'Médicament' }}</strong><br>
            <span class="muted">{{ $item->special_instructions ?: '' }}</span>
          </td>
          <td>{{ $item->dosage_prescribed }}<br><span class="muted">{{ $item->frequency }}</span></td>
          <td>{{ $item->duration_days }} jour(s)</td>
          <td class="num">{{ $item->quantity }}</td>
        </tr>
      @empty
        <tr><td colspan="4" class="muted">Aucun médicament prescrit.</td></tr>
      @endforelse
    </tbody>
  </table>

  @if ($prescription->notes)
    <p style="margin-top: 10px;"><strong>Conseils :</strong> {{ $prescription->notes }}</p>
  @endif

  <table style="margin-top: 14px;">
    <tr>
      <td class="muted" style="width: 55%;">
        @if ($verification)
          Code de vérification : <strong>{{ $verification }}</strong><br>
          Une pharmacie peut vérifier l’authenticité de cette ordonnance avec ce code.
        @endif
      </td>
      <td>
        <strong>{{ $doctorName }}</strong><br>
        <span class="muted">N° d’ordre : {{ $doctorLicence ?: 'à renseigner dans le profil du médecin' }}</span>
        <div class="sign"></div>
        <span class="muted">Signature et cachet du médecin</span>
      </td>
    </tr>
  </table>
</body>
</html>
