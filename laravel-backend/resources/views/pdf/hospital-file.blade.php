<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Fiche de séjour {{ $patient->patient_unique_id }}</title></head>
<body>
  @include('pdf.partials.frame')

  <div class="title">FICHE DE SÉJOUR HOSPITALIER</div>
  <div class="ref">Admission n° {{ $admission->id }} · statut <strong>{{ $admission->status }}</strong></div>

  <table class="box">
    <tr>
      <td>
        <strong>Patient</strong><br>
        {{ strtoupper($patient->last_name) }} {{ $patient->first_name }}<br>
        <span class="muted">Dossier {{ $patient->patient_unique_id }}{{ $patient->phone ? ' · Tél. '.$patient->phone : '' }}</span>
      </td>
      <td class="right">
        <span class="muted">Médecin responsable</span><br>{{ $doctorLabel ?? 'Non précisé' }}<br>
        <span class="muted">N° d’ordre : {{ $doctorLicence ?: 'à renseigner' }}</span>
      </td>
    </tr>
    <tr>
      <td>
        <span class="muted">Entrée</span><br>{{ $admission->admitted_at?->format('d/m/Y H:i') }}<br>
        <span class="muted">Motif : {{ $admission->reason ?: 'non précisé' }}</span>
      </td>
      <td class="right">
        <span class="muted">Lit</span><br>{{ $admission->bed?->code ?? '—' }} · {{ $admission->room?->name ?? '' }}<br>
        <span class="muted">Sortie : {{ $admission->discharged_at?->format('d/m/Y H:i') ?? 'en cours' }}</span>
      </td>
    </tr>
  </table>

  <p style="margin-top: 12px;"><strong>Mouvements de lits</strong></p>
  @if ($admission->transfers->isEmpty())
    <p class="muted">Aucun transfert.</p>
  @else
    <table class="items">
      <thead><tr><th>Date</th><th>Vers</th><th>Motif</th></tr></thead>
      <tbody>
        @foreach ($admission->transfers as $transfer)
          <tr><td>{{ $transfer->transferred_at?->format('d/m/Y H:i') }}</td><td>{{ $transfer->toBed?->code ?? '—' }}</td><td>{{ $transfer->reason ?? '' }}</td></tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <p style="margin-top: 12px;"><strong>Constantes récentes</strong></p>
  @forelse ($vitals as $v)
    <p class="muted">{{ $v->recorded_at?->format('d/m/Y H:i') }} · TA {{ $v->systolic_bp }}/{{ $v->diastolic_bp }} · FC {{ $v->heart_rate }} · T° {{ $v->temperature }} · SpO2 {{ $v->spo2 }} %</p>
  @empty
    <p class="muted">Aucune constante enregistrée.</p>
  @endforelse

  <p style="margin-top: 12px;"><strong>Compte rendu de sortie</strong></p>
  <p style="white-space: pre-line;">{{ $admission->discharge_summary ?: 'Compte rendu de sortie non encore rédigé.' }}</p>

  <table style="margin-top: 16px;">
    <tr>
      <td class="muted" style="width: 55%;">Code de vérification : <strong>{{ $verification }}</strong></td>
      <td><span class="muted">Cachet et signature du médecin responsable</span><div class="sign"></div></td>
    </tr>
  </table>
</body>
</html>
