<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Compte rendu opératoire {{ $patient->patient_unique_id }}</title></head>
<body>
  @include('pdf.partials.frame')

  <div class="title">COMPTE RENDU OPÉRATOIRE</div>
  <div class="ref">Intervention n° {{ $surgery->id }} · {{ $surgery->scheduled_at->format('d/m/Y à H:i') }}{{ $surgery->report_signed_at ? ' · signé le '.$surgery->report_signed_at->format('d/m/Y à H:i') : ' · NON SIGNÉ' }}</div>

  <table class="box">
    <tr>
      <td>
        <strong>Patient</strong><br>
        {{ strtoupper($patient->last_name) }} {{ $patient->first_name }}<br>
        <span class="muted">Dossier {{ $patient->patient_unique_id }}{{ $patient->phone ? ' · Tél. '.$patient->phone : '' }}</span>
      </td>
      <td class="right">
        <strong>Opération</strong><br>{{ $surgery->operation_type }}<br>
        <span class="muted">Salle {{ $surgery->room ?: 'non précisée' }}</span>
      </td>
    </tr>
    <tr>
      <td>
        <span class="muted">Chirurgien</span><br>{{ $surgeonLabel ?? 'Non précisé' }}<br>
        <span class="muted">N° d’ordre : {{ $surgeonLicence ?: 'à renseigner dans le profil du médecin' }}</span>
      </td>
      <td class="right">
        <span class="muted">Anesthésiste</span><br>{{ $anesthetistLabel ?? 'Non désigné' }}
      </td>
    </tr>
  </table>

  <p style="margin-top: 12px;"><strong>Compte rendu opératoire</strong></p>
  <p style="white-space: pre-line;">{{ $surgery->report }}</p>

  @if ($surgery->post_op_followup)
    <p style="margin-top: 10px;"><strong>Suivi post-opératoire</strong></p>
    <p style="white-space: pre-line;">{{ $surgery->post_op_followup }}</p>
  @endif

  <table style="margin-top: 16px;">
    <tr>
      <td class="muted" style="width: 55%;">
        @if ($verification)
          Code de vérification : <strong>{{ $verification }}</strong><br>
          Document signé électroniquement par le chirurgien assigné.
        @endif
      </td>
      <td>
        <strong>{{ $surgeonLabel ?? '' }}</strong><br>
        <span class="muted">N° d’ordre : {{ $surgeonLicence ?: 'à renseigner' }}</span>
        <div class="sign"></div>
        <span class="muted">Signature et cachet du chirurgien</span>
      </td>
    </tr>
  </table>
</body>
</html>
