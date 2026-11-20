{{-- Shared frame of every official document: style, header (logo or name, address, licence) and footer (CGU, verification). The "Page n / N" footer is drawn by App\Services\Documents\OfficialPdf. --}}
<style>
  @page { margin: 26px 30px 58px 30px; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #0f172a; margin: 0; }
  .head { border-bottom: 2px solid #047857; padding-bottom: 8px; }
  .clinic { font-size: 14px; font-weight: bold; color: #065f46; }
  .muted { color: #64748b; font-size: 9px; }
  .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 1px; margin: 12px 0 6px; }
  .ref { text-align: center; font-size: 9px; color: #475569; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 4px 3px; vertical-align: top; }
  .box { border: 1px solid #cbd5e1; padding: 6px; }
  .items th { text-align: left; font-size: 9px; color: #475569; border-bottom: 1px solid #cbd5e1; }
  .items td { border-bottom: 1px solid #f1f5f9; }
  .right { text-align: right; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .total td { font-size: 11px; font-weight: bold; padding-top: 6px; }
  .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 9px; }
  .solde { background: #d1fae5; color: #065f46; }
  .reste { background: #fef3c7; color: #92400e; }
  .sign { margin-top: 10px; height: 56px; border: 1px dashed #94a3b8; }
  .alert { color: #92400e; font-size: 9px; }
  .foot { position: fixed; bottom: -44px; left: 0; right: 0; border-top: 1px solid #cbd5e1; padding-top: 4px; font-size: 7.5px; color: #64748b; }
</style>

<div class="head">
  <table>
    <tr>
      <td>
        @if ($logo)
          <img src="{{ $logo }}" alt="Logo {{ $clinic->name }}" style="height: 44px; margin-bottom: 4px;"><br>
        @endif
        <div class="clinic">{{ $clinic->name }}</div>
        <div class="muted">{{ trim(($clinic->city ?? '').' '.($clinic->country ?? '')) }}</div>
        <div class="muted">{{ $clinic->settings['address'] ?? 'Adresse à renseigner par la direction' }}</div>
        <div class="muted">Tél. {{ $clinic->settings['phone'] ?? $clinic->admin_phone ?? 'à renseigner' }}</div>
      </td>
      <td class="right muted">
        Numéro d’agrément<br>
        @if ($licence)
          <strong>{{ $licence }}</strong>
        @else
          <span class="alert">Non encore renseigné : le directeur doit saisir l’agrément dans Paramètres › Clinique.</span>
        @endif
      </td>
    </tr>
  </table>
</div>

<div class="foot">
  Conditions générales d’utilisation version {{ config('clinic.terms_version') }} · Document officiel de {{ $clinic->name }} · Code de vérification <strong>{{ $verification }}</strong>
</div>
