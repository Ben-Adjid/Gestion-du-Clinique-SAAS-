<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Consentement éclairé {{ $patient->patient_unique_id }}</title></head>
<body>
  @include('pdf.partials.frame')

  <div class="title">CONSENTEMENT ÉCLAIRÉ À UNE INTERVENTION</div>
  <div class="ref">Intervention n° {{ $surgery->id }} · {{ $surgery->operation_type }} · recueilli le {{ $consent->signed_at->format('d/m/Y à H:i') }}</div>

  <table class="box">
    <tr>
      <td>
        <strong>Patient</strong><br>
        {{ strtoupper($patient->last_name) }} {{ $patient->first_name }}<br>
        <span class="muted">Dossier {{ $patient->patient_unique_id }}</span>
      </td>
      <td class="right">
        <span class="muted">Chirurgien</span><br>{{ $surgeonLabel ?? 'Non précisé' }}<br>
        <span class="muted">N° d’ordre : {{ $surgeonLicence ?: 'à renseigner' }}</span>
      </td>
    </tr>
  </table>

  <p style="margin-top: 12px;"><strong>Information donnée avant la décision</strong></p>
  <p style="white-space: pre-line;">{{ $consent->information_text }}</p>

  <p style="margin-top: 12px;">
    Je soussigné(e) <strong>{{ $consent->signer_name }}</strong>, qualité : <strong>{{ $consent->signer_relation === 'PATIENT' ? 'le patient lui-même' : ($consent->signer_relation === 'TUTEUR' ? 'tuteur légal' : 'proche désigné') }}</strong>,
    déclare avoir reçu cette information, pouvoir poser des questions, et consens à l’intervention ci-dessus.
  </p>

  <table style="margin-top: 16px;">
    <tr>
      <td class="muted" style="width: 55%;">
        Code de vérification : <strong>{{ $verification }}</strong>
      </td>
      <td>
        <span class="muted">Signature de la personne qui consent</span>
        <div class="sign"></div>
      </td>
    </tr>
  </table>
</body>
</html>
