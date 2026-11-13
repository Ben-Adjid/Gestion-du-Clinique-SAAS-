# Clinique+ — Gestion de clinique (SaaS)

Plateforme multi-cliniques : chaque clinique gère son personnel, ses patients et ses activités médicales dans un espace séparé.

## Fonctionnalités

- **Patients** : dossier, antécédents, allergies, contacts, consentements.
- **Rendez-vous** : demandes, agendas des médecins, plages horaires.
- **Consultations** : signes vitaux, diagnostics, tâches et sections de consultation.
- **Prescriptions** : médicaments, interactions médicamenteuses, pharmacie et lots.
- **Laboratoire et imagerie** : demandes, échantillons, résultats, examens et comptes rendus.
- **Hospitalisation** : lits et chirurgies.
- **Facturation et caisse** : factures, paiements, clôtures de caisse.
- **Personnel** : employés, présences, congés, paie.
- **Abonnements** : formules par clinique, paiements via Flutterwave, historique des événements.
- **Sécurité** : rôles et permissions, journal d'audit, signature numérique.

## Technologies

- **Backend** : Laravel (API REST), authentification Laravel Sanctum.
- **Frontend** : Next.js et React.
- **Base de données** : MySQL (SQLite en développement).

## Structure

```
laravel-backend/   API Laravel (modèles, contrôleurs, migrations)
react-frontend/    Interface Next.js
```

## Installation

### Backend

```bash
cd laravel-backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Frontend

```bash
cd react-frontend
npm install
```

Créez un fichier `.env.local` contenant l'adresse de l'API :

```bash
NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api/v1
```

Puis lancez le serveur de développement :

```bash
npm run dev
```

## Statut

Projet en développement.
