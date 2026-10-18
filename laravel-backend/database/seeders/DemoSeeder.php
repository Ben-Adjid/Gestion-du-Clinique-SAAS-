<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Consultation;
use App\Models\ConsultationTask;
use App\Models\DrugInteraction;
use App\Models\Employee;
use App\Models\ImagingExam;
use App\Models\ImagingRequest;
use App\Models\Invoice;
use App\Models\LabExam;
use App\Models\LabRequest;
use App\Models\MedicalCertificate;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PharmacyInventory;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VitalSign;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Realistic demonstration platform (Chad): four clinics on different plans, their staff,
 * patients and three weeks of activity. Run on an empty database:
 *   php artisan migrate:fresh --seed --seeder=DemoSeeder
 * Every account uses the password Password@12345.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password@12345';

    private const CLINICS = [
        [
            'code' => 'HARIRI', 'name' => 'Clinique Hariri', 'city' => 'N’Djamena', 'domain' => 'hariri.td',
            'address' => 'Avenue Charles de Gaulle, Moursal', 'phone' => '+235 22 51 40 40',
            'plan' => 'ENTERPRISE', 'scenario' => 'paid', 'patients' => 80,
            'districts' => ['Moursal', 'Chagoua', 'Farcha', 'Diguel', 'Klemat', 'Sabangali', 'Ambassatna', 'Dembé'],
            'staff' => [
                ['ADMIN_CLINIC', 'admin', 'Dr Abakar Mahamat Saleh', 'Directeur médical', 1200000],
                ['DOCTOR', 'medecin1', 'Dr Fatimé Hassan Brahim', 'Médecin généraliste', 900000, 'Médecine générale'],
                ['DOCTOR', 'medecin2', 'Dr Rimadjita Ndoubabé', 'Pédiatre', 1000000, 'Pédiatrie'],
                ['DOCTOR', 'medecin3', 'Dr Youssouf Adoum Ali', 'Gynécologue-obstétricien', 1100000, 'Gynécologie-obstétrique'],
                ['NURSE', 'infirmier1', 'Hawa Djibrine Oumar', 'Infirmière diplômée d’État', 350000],
                ['NURSE', 'infirmier2', 'Ngaba Allarassem', 'Infirmier diplômé d’État', 350000],
                ['RECEPTIONIST', 'accueil', 'Zenaba Issa Moussa', 'Réceptionniste', 200000],
                ['CASHIER', 'caisse', 'Khalil Haroun Ahmat', 'Caissier', 220000],
                ['PHARMACIST', 'pharmacie', 'Dr Grâce Nodjitolnan', 'Pharmacienne', 650000],
                ['LABORATORY_TECHNICIAN', 'labo', 'Daoud Nassour Hassan', 'Technicien de laboratoire', 380000],
                ['RADIOLOGIST', 'imagerie', 'Dr Esther Mbaïlaou', 'Radiologue', 950000],
                ['HR_MANAGER', 'rh', 'Achta Mahamat Abdelkerim', 'Responsable des ressources humaines', 450000],
            ],
        ],
        [
            'code' => 'ESPOIR', 'name' => 'Polyclinique Espoir', 'city' => 'Moundou', 'domain' => 'espoir-moundou.td',
            'address' => 'Quartier Doumbeur, route de Kélo', 'phone' => '+235 22 69 12 12',
            'plan' => 'PRO', 'scenario' => 'pending_declaration', 'patients' => 60,
            'districts' => ['Doumbeur', 'Quinze Ans', 'Djarabé', 'Guelkol', 'Mbombaya', 'Dokab'],
            'staff' => [
                ['ADMIN_CLINIC', 'admin', 'Dr Dingamadji Béassoum', 'Directeur', 1000000],
                ['DOCTOR', 'medecin1', 'Dr Remadji Guelmbaye', 'Médecin généraliste', 800000, 'Médecine générale'],
                ['DOCTOR', 'medecin2', 'Dr Ngarmadji Doumtangar', 'Médecin interniste', 900000, 'Médecine interne'],
                ['NURSE', 'infirmier1', 'Néloumta Kodbé', 'Infirmière', 300000],
                ['NURSE', 'infirmier2', 'Ruth Nadjilem', 'Infirmière', 300000],
                ['RECEPTIONIST', 'accueil', 'Djénom Allarabaye', 'Réceptionniste', 180000],
                ['CASHIER', 'caisse', 'Ndolembai Ngarta', 'Caissier', 190000],
                ['PHARMACIST', 'pharmacie', 'Dr Ache Ngaba', 'Pharmacienne', 550000],
                ['LABORATORY_TECHNICIAN', 'labo', 'Rimadjita Guelmbaye', 'Technicien de laboratoire', 320000],
                ['RADIOLOGIST', 'imagerie', 'Dr Nadjilem Doumtangar', 'Radiologue', 850000],
            ],
        ],
        [
            'code' => 'OUADDAI', 'name' => 'Centre médical du Ouaddaï', 'city' => 'Abéché', 'domain' => 'cm-ouaddai.td',
            'address' => 'Quartier Ridina, près du grand marché', 'phone' => '+235 22 69 80 80',
            'plan' => 'BASIC', 'scenario' => 'suspended', 'patients' => 30,
            'districts' => ['Ridina', 'Amsiguett', 'Bitéa', 'Djatinié', 'Hillé Kamis'],
            'staff' => [
                ['ADMIN_CLINIC', 'admin', 'Dr Ahmat Daoud Brahim', 'Directeur', 800000],
                ['DOCTOR', 'medecin1', 'Dr Kaltouma Issa Haroun', 'Médecin généraliste', 700000, 'Médecine générale'],
                ['NURSE', 'infirmier1', 'Mariam Saleh Ali', 'Infirmière', 280000],
                ['RECEPTIONIST', 'accueil', 'Nassour Oumar Adoum', 'Réceptionniste', 160000],
                ['CASHIER', 'caisse', 'Halimé Moussa Djibrine', 'Caissière', 170000],
                ['PHARMACIST', 'pharmacie', 'Dr Ibrahim Abakar Hassan', 'Pharmacien', 500000],
            ],
        ],
        [
            'code' => 'SAHEL', 'name' => 'Cabinet médical du Sahel', 'city' => 'Mongo', 'domain' => 'cabinet-sahel.td',
            'address' => 'Avenue principale, face à la préfecture', 'phone' => '+235 22 66 30 30',
            'plan' => 'BASIC', 'scenario' => 'trial', 'patients' => 8,
            'districts' => ['Centre', 'Kaltouma', 'Mongo-Nord'],
            'staff' => [
                ['ADMIN_CLINIC', 'admin', 'Dr Nafissa Brahim Saleh', 'Médecin responsable', 600000],
                ['DOCTOR', 'medecin1', 'Dr Hassan Idriss Mahamat', 'Médecin généraliste', 600000, 'Médecine générale'],
                ['NURSE', 'infirmier1', 'Amina Youssouf Ali', 'Infirmière', 250000],
            ],
        ],
    ];

    private const MALE_FIRST = ['Mahamat', 'Abakar', 'Hassan', 'Idriss', 'Moussa', 'Adoum', 'Brahim', 'Youssouf', 'Ali', 'Oumar', 'Saleh', 'Djibrine', 'Haroun', 'Abdelkerim', 'Issa', 'Ahmat', 'Khalil', 'Daoud', 'Nassour', 'Ngaba', 'Allarabaye', 'Dingamadji', 'Rimadjita', 'Ndolembai', 'Ngarmadji', 'Béassoum'];
    private const FEMALE_FIRST = ['Fatimé', 'Hawa', 'Achta', 'Khadidja', 'Zara', 'Mariam', 'Amina', 'Halimé', 'Kaltouma', 'Zenaba', 'Nafissa', 'Ruth', 'Grâce', 'Esther', 'Néloumta', 'Remadji', 'Djénom', 'Ache', 'Mbaïndo', 'Nodjilembaye'];
    private const LAST_NAMES = ['Mahamat', 'Abakar', 'Hassan', 'Saleh', 'Brahim', 'Oumar', 'Adoum', 'Moussa', 'Haroun', 'Djibrine', 'Issa', 'Ahmat', 'Ndoubabé', 'Djimadoum', 'Nodjitolnan', 'Mbaïlaou', 'Allarassem', 'Ngaba', 'Nadjilem', 'Béassoum', 'Doumtangar', 'Kodbé', 'Guelmbaye', 'Ngarmadji', 'Abdelkerim', 'Daoud', 'Youssouf'];
    private const PROFESSIONS = ['Commerçante', 'Enseignant', 'Cultivateur', 'Éleveur', 'Élève', 'Étudiante', 'Fonctionnaire', 'Ménagère', 'Chauffeur', 'Couturière', 'Mécanicien', 'Infirmier retraité', 'Sans emploi'];

    private const LAB_EXAMS = [
        ['HEMATOLOGY', 'Numération formule sanguine (NFS)', 5000, 'g/dL', 12.0, 16.0],
        ['PARASITOLOGY', 'Goutte épaisse (paludisme)', 2000, 'parasites/µL', null, null],
        ['BIOCHEMISTRY', 'Glycémie à jeun', 2000, 'g/L', 0.70, 1.10],
        ['BIOCHEMISTRY', 'Créatinine', 3500, 'mg/L', 6.0, 12.0],
        ['BIOCHEMISTRY', 'Transaminases (ASAT/ALAT)', 6000, 'UI/L', 5.0, 40.0],
        ['IMMUNOLOGY', 'Protéine C réactive (CRP)', 4000, 'mg/L', 0.0, 6.0],
        ['MICROBIOLOGY', 'Examen cytobactériologique des urines (ECBU)', 6000, 'leucocytes/mm³', 0.0, 10.0],
        ['IMMUNOHEMATOLOGY', 'Groupe sanguin et rhésus', 3000, null, null, null],
    ];

    private const IMAGING_EXAMS = [
        ['XRAY', 'Radiographie du thorax (face)', 15000],
        ['ULTRASOUND', 'Échographie abdominale', 20000],
        ['ULTRASOUND', 'Échographie obstétricale', 20000],
        ['XRAY', 'Radiographie d’un membre', 12000],
    ];

    /** name, dosage, form, manufacturer, selling price, unit cost, stock, alert threshold */
    private const MEDICATIONS = [
        ['Paracétamol', '500 mg', 'Comprimé (boîte de 20)', 'Cipla', 500, 250, 400, 50],
        ['Amoxicilline', '500 mg', 'Gélule (boîte de 12)', 'Sandoz', 1500, 800, 180, 30],
        ['Artéméther-Luméfantrine', '20/120 mg', 'Comprimé (plaquette de 24)', 'Novartis', 2500, 1400, 220, 40],
        ['Artésunate injectable', '60 mg', 'Flacon', 'Guilin', 3500, 2000, 25, 30],
        ['Métronidazole', '250 mg', 'Comprimé (boîte de 20)', 'Sanofi', 800, 400, 150, 30],
        ['Ibuprofène', '400 mg', 'Comprimé (boîte de 20)', 'Cipla', 700, 350, 160, 30],
        ['Diclofénac', '50 mg', 'Comprimé (boîte de 20)', 'Novartis', 900, 450, 8, 20],
        ['Sels de réhydratation orale (SRO)', '20,5 g', 'Sachet', 'Unicef', 200, 100, 500, 100],
        ['Fer + acide folique', '200/0,4 mg', 'Comprimé (boîte de 30)', 'Cipla', 1000, 500, 200, 40],
        ['Metformine', '500 mg', 'Comprimé (boîte de 30)', 'Merck', 1500, 800, 90, 20],
        ['Amlodipine', '5 mg', 'Comprimé (boîte de 30)', 'Pfizer', 2000, 1100, 70, 20],
        ['Oméprazole', '20 mg', 'Gélule (boîte de 14)', 'Sandoz', 1200, 600, 110, 20],
        ['Ciprofloxacine', '500 mg', 'Comprimé (boîte de 10)', 'Bayer', 1800, 900, 85, 20],
        ['Ceftriaxone injectable', '1 g', 'Flacon', 'Roche', 3000, 1700, 40, 15],
    ];

    /** CIM-10 code, label, reason, examination, lab exams, imaging exams, medications [name, dosage, frequency, days, qty], care task */
    private const DIAGNOSES = [
        ['B50.9', 'Paludisme à Plasmodium falciparum, simple', 'Fièvre depuis 3 jours, frissons, céphalées et courbatures.', 'Patient fébrile, conjonctives légèrement pâles, pas de signe de gravité.', ['Goutte épaisse (paludisme)', 'Numération formule sanguine (NFS)'], [], [['Artéméther-Luméfantrine', '4 comprimés', '2 fois par jour', 3, 1], ['Paracétamol', '1 comprimé', '3 fois par jour', 3, 1]], null, 'fever'],
        ['B50.0', 'Paludisme grave à Plasmodium falciparum', 'Fièvre élevée, vomissements répétés et grande fatigue.', 'Prostration, température à 39,8 °C, déshydratation modérée.', ['Goutte épaisse (paludisme)', 'Numération formule sanguine (NFS)', 'Créatinine'], [], [['Artésunate injectable', '2,4 mg/kg', 'à H0, H12, H24 puis 1 fois par jour', 3, 5], ['Paracétamol', '1 comprimé', '3 fois par jour', 3, 1]], 'Artésunate IV à H0, H12 et H24, surveillance de la température toutes les 4 heures', 'fever'],
        ['J06.9', 'Infection aiguë des voies respiratoires supérieures', 'Toux, écoulement nasal et maux de gorge depuis 4 jours.', 'Pharynx érythémateux, auscultation pulmonaire normale.', [], [], [['Paracétamol', '1 comprimé', '3 fois par jour', 5, 1]], null, 'mild'],
        ['J18.9', 'Pneumopathie, sans précision', 'Toux productive, fièvre et douleur thoracique depuis 5 jours.', 'Râles crépitants à la base droite, fréquence respiratoire augmentée.', ['Numération formule sanguine (NFS)', 'Protéine C réactive (CRP)'], ['Radiographie du thorax (face)'], [['Amoxicilline', '2 gélules', '3 fois par jour', 7, 2], ['Paracétamol', '1 comprimé', '3 fois par jour', 5, 1]], null, 'fever'],
        ['A09', 'Gastro-entérite d’origine présumée infectieuse', 'Diarrhée liquide et douleurs abdominales depuis 2 jours.', 'Abdomen souple, signes de déshydratation légère.', [], [], [['Sels de réhydratation orale (SRO)', '1 sachet dans 1 litre d’eau', 'après chaque selle', 3, 10], ['Métronidazole', '1 comprimé', '3 fois par jour', 5, 1]], null, 'mild'],
        ['I10', 'Hypertension artérielle essentielle', 'Contrôle tensionnel, céphalées occasionnelles.', 'Tension élevée à deux reprises, examen cardiovasculaire sans particularité.', ['Créatinine'], [], [['Amlodipine', '1 comprimé', '1 fois par jour le matin', 30, 1]], null, 'hypertension'],
        ['E11.9', 'Diabète de type 2 sans complication', 'Suivi du diabète, soif et envie fréquente d’uriner.', 'État général conservé, pieds sans lésion.', ['Glycémie à jeun', 'Créatinine'], [], [['Metformine', '1 comprimé', '2 fois par jour aux repas', 30, 2]], null, 'normal'],
        ['N39.0', 'Infection des voies urinaires', 'Brûlures mictionnelles et envies fréquentes d’uriner.', 'Sensibilité sus-pubienne, pas de fièvre.', ['Examen cytobactériologique des urines (ECBU)'], [], [['Ciprofloxacine', '1 comprimé', '2 fois par jour', 5, 1]], null, 'normal'],
        ['K29.7', 'Gastrite, sans précision', 'Brûlures d’estomac après les repas.', 'Sensibilité épigastrique à la palpation.', [], ['Échographie abdominale'], [['Oméprazole', '1 gélule', '1 fois par jour avant le repas', 14, 1]], null, 'normal'],
        ['Z34.9', 'Surveillance d’une grossesse normale', 'Consultation prénatale de routine.', 'Hauteur utérine conforme au terme, bruits du cœur fœtal présents.', ['Numération formule sanguine (NFS)', 'Groupe sanguin et rhésus'], ['Échographie obstétricale'], [['Fer + acide folique', '1 comprimé', '1 fois par jour', 30, 1]], null, 'normal'],
        ['D57.0', 'Drépanocytose avec crise', 'Douleurs osseuses intenses depuis la veille.', 'Douleurs des membres, pâleur, pas de signe de détresse.', ['Numération formule sanguine (NFS)'], [], [['Paracétamol', '1 comprimé', '4 fois par jour', 5, 2]], 'Hydratation intraveineuse et évaluation de la douleur toutes les 2 heures', 'fever'],
    ];

    private const ALLERGIES = [['Pénicilline', 'HIGH'], ['Sulfamides', 'MEDIUM'], ['Arachide', 'MEDIUM'], ['Aspirine', 'LOW']];
    private const HISTORY = ['Drépanocytose', 'Hypertension artérielle', 'Diabète de type 2', 'Asthme', 'Paludisme à répétition', 'Ulcère gastrique', 'Césarienne'];

    private string $passwordHash;
    private int $saasSequence = 0;
    /** @var array<int, int> */
    private array $certificateSequence = [];
    /** @var array<string, int> */
    private array $sampleSequence = [];
    /** @var array<string, int> */
    private array $roles = [];

    public function run(): void
    {
        mt_srand(2026);
        $this->call([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class, Cim10Seeder::class]);
        $this->passwordHash = Hash::make(self::PASSWORD);
        $this->roles = Role::query()->pluck('id', 'name')->all();

        Model::unguarded(function () {
            User::create([
                'name' => 'Équipe Clinique+',
                'email' => 'superadmin@example.com',
                'phone' => '+235 66 00 00 01',
                'password' => $this->passwordHash,
                'clinic_id' => null,
                'role_id' => $this->roles['SUPER_ADMIN'],
                'status' => 'ACTIVE',
            ]);

            foreach (self::CLINICS as $definition) {
                DB::transaction(fn () => $this->seedClinic($definition));
            }
        });
    }

    private function seedClinic(array $definition): void
    {
        $plan = SubscriptionPlan::query()->where('code', $definition['plan'])->firstOrFail();
        $clinic = Clinic::create([
            'name' => $definition['name'],
            'code' => $definition['code'],
            'city' => $definition['city'],
            'country' => 'Tchad',
            'subscription_plan' => $plan->code,
            'admin_email' => "admin@{$definition['domain']}",
            'admin_phone' => $this->phone(),
            'settings' => ['address' => $definition['address'], 'phone' => $definition['phone']],
            'status' => 'ACTIVE',
            'created_at' => now()->subMonths(5),
        ]);

        $staff = $this->seedStaff($clinic, $definition);
        $this->seedSubscription($clinic, $plan, $definition['scenario'], $staff['ADMIN_CLINIC'][0]);

        $withLab = $plan->includesModule('lab');
        $labExams = $withLab ? $this->seedLabExams($clinic) : [];
        $imagingExams = $plan->includesModule('imaging') ? $this->seedImagingExams($clinic) : [];
        $medications = $this->seedMedications($clinic);

        $patients = $this->seedPatients($clinic, $definition);
        $daysBack = $definition['scenario'] === 'trial' ? 7 : 21;
        $this->seedActivity($clinic, $staff, $patients, $labExams, $imagingExams, $medications, $daysBack, $definition['scenario'] === 'suspended');
        if ($plan->includesModule('hr')) {
            $this->seedHumanResources($clinic, $staff);
        }
        if ($plan->includesModule('patient_portal')) {
            $this->seedPortalPatient($clinic, $definition['domain']);
        }
    }

    /** @return array<string, list<User>> */
    private function seedStaff(Clinic $clinic, array $definition): array
    {
        $staff = [];
        foreach ($definition['staff'] as $index => $member) {
            [$role, $login, $name, $position, $salary] = $member;
            $user = User::create([
                'name' => $name,
                'email' => "{$login}@{$definition['domain']}",
                'phone' => $this->phone(),
                'password' => $this->passwordHash,
                'clinic_id' => $clinic->id,
                'role_id' => $this->roles[$role],
                'status' => 'ACTIVE',
                'metadata' => isset($member[5]) ? ['specialty' => $member[5]] : null,
                'last_login_at' => now()->subHours(mt_rand(1, 72)),
            ]);
            [$firstName, $lastName] = $this->splitName($name);
            $this->seedEmployee($clinic, $index + 1, $user, $firstName, $lastName, $position, $salary, self::DEPARTMENTS[$role] ?? 'Administration');
            $staff[$role][] = $user;
        }
        // Staff without an application account.
        $offset = count($definition['staff']);
        foreach ([['Ngarmadji', 'Kodbé', 'Agent d’entretien', 90000, 'Entretien'], ['Brahim', 'Issa', 'Gardien', 85000, 'Sécurité']] as $index => [$firstName, $lastName, $position, $salary, $department]) {
            $this->seedEmployee($clinic, $offset + $index + 1, null, $firstName, $lastName, $position, $salary, $department);
        }

        return $staff;
    }

    private const DEPARTMENTS = [
        'ADMIN_CLINIC' => 'Direction', 'DOCTOR' => 'Médecine', 'NURSE' => 'Soins infirmiers', 'RECEPTIONIST' => 'Accueil',
        'CASHIER' => 'Caisse', 'PHARMACIST' => 'Pharmacie', 'LABORATORY_TECHNICIAN' => 'Laboratoire', 'RADIOLOGIST' => 'Imagerie',
        'HR_MANAGER' => 'Ressources humaines',
    ];

    private function seedEmployee(Clinic $clinic, int $number, ?User $user, string $firstName, string $lastName, string $position, float $salary, string $department): Employee
    {
        $method = $this->pickWeighted([['MOBILE_MONEY', 50], ['BANK_TRANSFER', 30], ['CASH', 20]]);
        $fixedTerm = $number % 5 === 0;

        return Employee::create([
            'clinic_id' => $clinic->id,
            'user_id' => $user?->id,
            'employee_number' => 'MAT-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'position' => $position,
            'department' => $department,
            'contract_type' => $fixedTerm ? 'CDD' : 'CDI',
            'contract_end_date' => $fixedTerm ? now()->addMonths(mt_rand(2, 10))->endOfMonth()->toDateString() : null,
            'phone' => $user?->phone ?? $this->phone(),
            'national_id' => sprintf('TD-%02d-%07d', mt_rand(1, 23), mt_rand(1000000, 9999999)),
            'address' => 'Quartier '.$this->pick(['Moursal', 'Chagoua', 'Farcha', 'Kabalaye', 'Dembé', 'Gassi']),
            'cnps_number' => sprintf('%07d-%s', mt_rand(1000000, 9999999), chr(mt_rand(65, 90))),
            'payment_method' => $method,
            'payment_account' => match ($method) {
                'MOBILE_MONEY' => $this->phone(),
                'BANK_TRANSFER' => 'TD'.mt_rand(10, 99).' '.mt_rand(10000, 99999).' '.mt_rand(10000000, 99999999),
                default => null,
            },
            'emergency_contact' => $this->pick(self::MALE_FIRST).' '.$lastName.' — '.$this->phone(),
            'base_salary' => $salary,
            'hire_date' => now()->subMonths(mt_rand(6, 60))->startOfMonth()->toDateString(),
            'status' => 'ACTIVE',
        ]);
    }

    /** Attendance of the last weeks, leave requests and two months of payroll. */
    private function seedHumanResources(Clinic $clinic, array $staff): void
    {
        $employees = Employee::query()->where('clinic_id', $clinic->id)->get();
        $settings = \App\Services\HumanResources::settings($clinic->id);
        $reviewer = $staff['HR_MANAGER'][0] ?? $staff['ADMIN_CLINIC'][0];

        // Leave: one past and one upcoming approved, two pending, one refused.
        $leaves = [
            [0, 'VACATION', now()->subDays(20), now()->subDays(16), 'APPROVED', null],
            [1, 'VACATION', now()->addDays(10), now()->addDays(15), 'APPROVED', null],
            [2, 'VACATION', now()->addDays(20), now()->addDays(27), 'PENDING', null],
            [3, 'SICK', now()->addDay(), now()->addDays(2), 'PENDING', 'Intervention chirurgicale programmée.'],
            [4, 'UNPAID', now()->addDays(5), now()->addDays(6), 'REJECTED', 'Période de forte activité.'],
        ];
        foreach ($leaves as [$index, $type, $start, $end, $status, $note]) {
            $employee = $employees[$index % $employees->count()];
            \App\Models\Leave::create([
                'clinic_id' => $clinic->id,
                'employee_id' => $employee->id,
                'leave_type' => $type,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days_count' => \App\Services\HumanResources::workingDays($start, $end),
                'status' => $status,
                'reason' => $type === 'SICK' ? $note : ($type === 'UNPAID' ? 'Voyage familial.' : null),
                'approved_by' => $status === 'PENDING' ? null : $reviewer->id,
                'approved_at' => $status === 'PENDING' ? null : $start->copy()->subDays(7),
                'review_note' => $status === 'REJECTED' ? $note : null,
                'created_at' => $start->copy()->subDays(10),
            ]);
        }
        $onLeave = \App\Models\Leave::query()->where('clinic_id', $clinic->id)->where('status', 'APPROVED')->get();

        // Attendance over the two payroll months and the current one.
        $workStart = Carbon::parse(today()->toDateString().' '.$settings['work_start']);
        for ($day = now()->subMonthsNoOverflow(2)->startOfMonth(); $day->lte(today()); $day->addDay()) {
            if ($day->isSunday()) {
                continue;
            }
            foreach ($employees as $employee) {
                $inLeave = $onLeave->contains(fn ($leave) => $leave->employee_id === $employee->id && $day->between($leave->start_date, $leave->end_date));
                if ($inLeave || $day->lt($employee->hire_date)) {
                    continue;
                }
                $status = $this->pickWeighted([['PRESENT', 88], ['LATE', 9], ['ABSENT', 3]]);
                $arrival = $day->copy()->setTimeFrom($workStart)->addMinutes($status === 'LATE' ? mt_rand(20, 75) : mt_rand(-25, 10));
                // Usually a normal day; occasionally a late evening (guard duty, emergency).
                $departure = $arrival->copy()->addHours(8)->addMinutes(mt_rand(1, 10) === 1 ? mt_rand(60, 180) : mt_rand(-30, 15));
                if ($day->isToday()) {
                    if ($arrival->gt(now()) || mt_rand(1, 5) === 1) {
                        continue;
                    }
                    $departure = null;
                }
                \App\Models\Attendance::create([
                    'clinic_id' => $clinic->id,
                    'employee_id' => $employee->id,
                    'work_date' => $day->toDateString(),
                    'check_in_datetime' => $status === 'ABSENT' ? null : $arrival,
                    'check_out_datetime' => $status === 'ABSENT' ? null : $departure,
                    'status' => $status,
                    'check_in_method' => $status === 'ABSENT' ? null : ($employee->user_id ? 'MOBILE' : 'BADGE'),
                    'notes' => $status === 'ABSENT' ? 'Absence non justifiée' : null,
                    'recorded_by' => $status === 'ABSENT' ? $reviewer->id : null,
                ]);
            }
        }

        // Payroll: the month before last is paid, last month waits for the director's approval.
        $director = $staff['ADMIN_CLINIC'][0];
        foreach ([2, 1] as $monthsAgo) {
            $period = now()->subMonthsNoOverflow($monthsAgo);
            \App\Services\HumanResources::generateMonth($clinic->id, (int) $period->year, (int) $period->month);
            if ($monthsAgo === 2) {
                \App\Models\Payroll::query()->where('clinic_id', $clinic->id)->where('year', $period->year)->where('month', $period->month)->get()
                    ->each(fn ($payroll) => $payroll->update([
                        'status' => 'PAID',
                        'approved_by' => $director->id,
                        'approved_at' => $period->copy()->endOfMonth()->subDays(2),
                        'paid_by' => $reviewer->id,
                        'paid_datetime' => $period->copy()->endOfMonth(),
                        'payment_method' => $payroll->employee->payment_method,
                        'payment_reference' => $payroll->employee->payment_method === 'CASH' ? null : 'SAL-'.$period->format('ym').'-'.$payroll->employee_id,
                    ]));
            }
        }
    }

    /** A patient account to try the portal: the patient with the most signed documents. */
    private function seedPortalPatient(Clinic $clinic, string $domain): void
    {
        $patientId = Consultation::query()->where('clinic_id', $clinic->id)->where('status', 'SIGNED')
            ->selectRaw('patient_id, COUNT(*) as total')->groupBy('patient_id')->orderByDesc('total')->value('patient_id');
        $patient = Patient::find($patientId);
        if (!$patient) {
            return;
        }
        $user = User::create([
            'name' => "{$patient->first_name} {$patient->last_name}",
            'email' => "patient@{$domain}",
            'phone' => $patient->phone,
            'password' => $this->passwordHash,
            'clinic_id' => $clinic->id,
            'role_id' => $this->roles['PATIENT'],
            'status' => 'ACTIVE',
        ]);
        $patient->update(['user_id' => $user->id]);
        // An upcoming appointment to show on the portal.
        Appointment::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => Consultation::query()->where('patient_id', $patient->id)->value('doctor_id'),
            'appointment_datetime' => now()->addDays(4)->setTime(10, 30),
            'status' => 'SCHEDULED',
            'reason' => 'Consultation de contrôle',
        ]);
    }

    private function seedSubscription(Clinic $clinic, SubscriptionPlan $plan, string $scenario, User $admin): void
    {
        $today = Carbon::today();
        $start = match ($scenario) {
            'trial' => $today->copy()->subDays(9),
            'suspended' => $today->copy()->subDays(20),
            default => $today->copy()->subDays(3),
        };
        $subscription = ClinicSubscription::create([
            'clinic_id' => $clinic->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'MONTHLY',
            'status' => match ($scenario) { 'trial' => 'TRIAL', 'suspended' => 'SUSPENDED', default => 'ACTIVE' },
            'price' => $plan->monthly_price,
            'current_period_start' => $start,
            'current_period_end' => $scenario === 'trial' ? $start->copy()->addDays($plan->trial_days - 1) : $start->copy()->addMonthNoOverflow()->subDay(),
            'trial_ends_at' => $scenario === 'trial' ? $start->copy()->addDays($plan->trial_days - 1) : null,
            'grace_days' => (int) config('clinic.saas_payment_terms_days'),
            'created_at' => $scenario === 'trial' ? $start : now()->subMonths(5),
        ]);
        $this->event($clinic, 'CREATED', $scenario === 'trial'
            ? "Abonnement {$plan->name} démarré avec {$plan->trial_days} jours d’essai."
            : "Abonnement {$plan->name} démarré (facturation mensuelle).", $subscription->created_at);

        if ($scenario === 'trial') {
            return;
        }

        $methods = [['ORANGE_MONEY', 'OM'], ['MOOV_MONEY', 'MV'], ['AIRTEL_MONEY', 'AM'], ['BANK_TRANSFER', 'VIR']];
        $previousMonths = $scenario === 'suspended' ? 2 : 4;
        for ($month = $previousMonths; $month >= 1; $month--) {
            $periodStart = $start->copy()->subMonthsNoOverflow($month);
            $invoice = $this->saasInvoice($subscription, $periodStart, 'PAID');
            [$method, $prefix] = $methods[mt_rand(0, 3)];
            $paidOn = $periodStart->copy()->addDays(mt_rand(1, 6));
            SaasPayment::create([
                'clinic_id' => $clinic->id,
                'saas_invoice_id' => $invoice->id,
                'amount' => $invoice->amount,
                'method' => $method,
                'reference' => sprintf('%s%s.%04d', $prefix, $paidOn->format('ymd'), mt_rand(1000, 9999)),
                'paid_on' => $paidOn,
                'status' => 'CONFIRMED',
                'declared_by' => $admin->id,
                'confirmed_at' => $paidOn->copy()->addDay(),
            ]);
            $invoice->update(['paid_at' => $paidOn]);
            $this->event($clinic, 'PAYMENT', "Paiement de ".number_format($invoice->amount, 0, ',', ' ')." FCFA validé sur {$invoice->number}.", $paidOn);
        }

        $current = $this->saasInvoice($subscription, $start, match ($scenario) { 'suspended' => 'OVERDUE', default => 'PENDING' });
        if ($scenario === 'pending_declaration') {
            SaasPayment::create([
                'clinic_id' => $clinic->id,
                'saas_invoice_id' => $current->id,
                'amount' => $current->amount,
                'method' => 'AIRTEL_MONEY',
                'reference' => 'AM'.$today->format('ymd').'.5521',
                'paid_on' => $today,
                'status' => 'PENDING',
                'declared_by' => $admin->id,
                'notes' => 'Paiement effectué depuis le compte Airtel Money de la clinique.',
            ]);
            $this->event($clinic, 'PAYMENT_DECLARED', "Paiement déclaré sur {$current->number}, en attente de validation.", $today);
        }
        if ($scenario === 'suspended') {
            $clinic->update(['status' => 'SUSPENDED']);
            $this->event($clinic, 'INVOICE_OVERDUE', "Facture {$current->number} en retard de paiement.", $current->due_date->copy()->addDay());
            $this->event($clinic, 'SUSPENDED', 'Clinique suspendue automatiquement pour impayé.', $current->due_date->copy()->addDays(8));
            UserNotification::create([
                'clinic_id' => $clinic->id,
                'user_id' => $admin->id,
                'type' => 'SUBSCRIPTION',
                'title' => 'Clinique suspendue pour impayé',
                'message' => "La facture {$current->number} n’a pas été réglée. Déclarez votre paiement pour réactiver la clinique.",
            ]);
        }
    }

    private function saasInvoice(ClinicSubscription $subscription, Carbon $periodStart, string $status): SaasInvoice
    {
        $this->saasSequence++;

        return SaasInvoice::create([
            'clinic_id' => $subscription->clinic_id,
            'subscription_id' => $subscription->id,
            'number' => sprintf('SAAS-%d-%06d', $periodStart->year, $this->saasSequence),
            'plan_code' => $subscription->plan->code,
            'billing_cycle' => 'MONTHLY',
            'period_start' => $periodStart,
            'period_end' => $periodStart->copy()->addMonthNoOverflow()->subDay(),
            'amount' => $subscription->price,
            'currency' => 'XAF',
            'status' => $status,
            'due_date' => $periodStart->copy()->addDays($subscription->grace_days),
            'issued_at' => $periodStart,
        ]);
    }

    /** @return array<string, LabExam> */
    private function seedLabExams(Clinic $clinic): array
    {
        $exams = [];
        foreach (self::LAB_EXAMS as [$type, $name, $price, $unit, $min, $max]) {
            $exams[$name] = LabExam::create([
                'clinic_id' => $clinic->id,
                'exam_type' => $type,
                'name' => $name,
                'reference_values' => ['price' => $price, 'unit' => $unit, 'min' => $min, 'max' => $max],
                'price' => $price,
                'unit' => $unit,
                'reference_min' => $min,
                'reference_max' => $max,
                'sample_type' => match ($type) {
                    'MICROBIOLOGY' => 'Urines (milieu de jet)',
                    default => 'Sang veineux',
                },
            ]);
        }

        return $exams;
    }

    /** @return array<string, ImagingExam> */
    private function seedImagingExams(Clinic $clinic): array
    {
        $exams = [];
        foreach (self::IMAGING_EXAMS as [$type, $name, $price]) {
            $exams[$name] = ImagingExam::create(['clinic_id' => $clinic->id, 'exam_type' => $type, 'name' => $name, 'price' => $price]);
        }

        return $exams;
    }

    /** @return array<string, Medication> */
    private function seedMedications(Clinic $clinic): array
    {
        $medications = [];
        foreach (self::MEDICATIONS as [$name, $dosage, $form, $manufacturer, $price, $cost, $stock, $threshold]) {
            $medication = Medication::create([
                'clinic_id' => $clinic->id,
                'name' => $name,
                'dosage' => $dosage,
                'form' => $form,
                'manufacturer' => $manufacturer,
                'price' => $price,
            ]);
            $inventory = PharmacyInventory::create([
                'clinic_id' => $clinic->id,
                'medication_id' => $medication->id,
                'quantity' => $stock,
                'unit_cost' => $cost,
                'selling_price' => $price,
                'min_threshold' => $threshold,
            ]);
            // Two lots per product: an older one (sometimes close to expiry) and a recent delivery.
            $soonExpired = mt_rand(1, 6) === 1;
            $older = intdiv($stock, 3);
            $lots = [
                [$older, $soonExpired ? now()->addDays(mt_rand(10, 45)) : now()->addMonths(mt_rand(4, 10)), now()->subMonths(mt_rand(10, 18))],
                [$stock - $older, now()->addMonths(mt_rand(14, 30)), now()->subMonths(mt_rand(1, 4))],
            ];
            foreach ($lots as $index => [$quantity, $expiry, $made]) {
                DB::table('pharmacy_lots')->insert([
                    'inventory_id' => $inventory->id,
                    'lot_number' => strtoupper(substr($this->ascii($manufacturer), 0, 3)).'-'.mt_rand(10000, 99999).chr(65 + $index),
                    'manufacturing_date' => $made->toDateString(),
                    'expiration_date' => $expiry->toDateString(),
                    'quantity' => $quantity,
                    'initial_quantity' => $quantity + mt_rand(0, 40),
                    'unit_cost' => $cost,
                    'created_at' => $made,
                    'updated_at' => now(),
                ]);
            }
            // One product has a forgotten expired lot, to show the alert and the destruction workflow.
            if ($name === 'Diclofénac') {
                DB::table('pharmacy_lots')->insert([
                    'inventory_id' => $inventory->id,
                    'lot_number' => 'NOV-EXP01',
                    'manufacturing_date' => now()->subYears(3)->toDateString(),
                    'expiration_date' => now()->subDays(12)->toDateString(),
                    'quantity' => 6,
                    'initial_quantity' => 40,
                    'unit_cost' => $cost,
                    'created_at' => now()->subYears(2),
                    'updated_at' => now(),
                ]);
                $inventory->increment('quantity', 6);
            }
            $medications[$name] = $medication;
        }

        $pairs = [
            ['Ibuprofène', 'Diclofénac', 'HIGH', 'Association de deux anti-inflammatoires non stéroïdiens : risque majoré d’ulcère et d’hémorragie digestive.'],
            ['Métronidazole', 'Ciprofloxacine', 'LOW', 'Troubles digestifs plus fréquents ; surveiller la tolérance.'],
            ['Ibuprofène', 'Amlodipine', 'MEDIUM', 'Les AINS diminuent l’effet antihypertenseur ; contrôler la tension.'],
        ];
        foreach ($pairs as [$first, $second, $severity, $description]) {
            $ids = [$medications[$first]->id, $medications[$second]->id];
            DrugInteraction::create(['drug_id_1' => min($ids), 'drug_id_2' => max($ids), 'severity' => $severity, 'description' => $description]);
        }

        return $medications;
    }

    /** @return list<Patient> */
    private function seedPatients(Clinic $clinic, array $definition): array
    {
        $patients = [];
        for ($index = 1; $index <= $definition['patients']; $index++) {
            $female = mt_rand(0, 1) === 1;
            $age = $this->pickWeighted([[mt_rand(0, 4), 18], [mt_rand(5, 17), 20], [mt_rand(18, 45), 40], [mt_rand(46, 80), 22]]);
            $birth = now()->subYears($age)->subDays(mt_rand(0, 360));
            $firstName = $this->pick($female ? self::FEMALE_FIRST : self::MALE_FIRST);
            $lastName = $this->pick(self::LAST_NAMES);
            $patient = Patient::create([
                'clinic_id' => $clinic->id,
                'patient_unique_id' => sprintf('%s-%d-%06d', $clinic->code, now()->year, $index),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'gender' => $female ? 'F' : 'M',
                'date_of_birth' => $birth->toDateString(),
                'phone' => $age >= 16 ? $this->phone() : null,
                'email' => $age >= 18 && mt_rand(1, 4) === 1 ? strtolower($this->ascii($firstName.'.'.$lastName)).mt_rand(1, 99).'@gmail.com' : null,
                'national_id' => $age >= 18 && mt_rand(1, 3) > 1 ? sprintf('TD-%02d-%07d', mt_rand(1, 23), $clinic->id * 1000000 + $index) : null,
                'marital_status' => $age < 18 ? 'SINGLE' : $this->pickWeighted([['MARRIED', 60], ['SINGLE', 25], ['WIDOWED', 8], ['DIVORCED', 7]]),
                'profession' => $age < 18 ? ($age < 6 ? null : 'Élève') : $this->pick(self::PROFESSIONS),
                'address' => 'Quartier '.$this->pick($definition['districts']),
                'city' => $definition['city'],
                'blood_group' => mt_rand(1, 3) === 1 ? null : $this->pickWeighted([['O+', 45], ['A+', 22], ['B+', 20], ['AB+', 4], ['O-', 4], ['A-', 2], ['B-', 2], ['AB-', 1]]),
                'created_at' => now()->subDays(mt_rand(25, 150)),
            ]);
            $patient->emergencyContacts()->create([
                'emergency_contact_name' => $this->pick(self::MALE_FIRST).' '.$lastName,
                'emergency_contact_phone' => $this->phone(),
                'relationship' => $age < 18 ? $this->pick(['Père', 'Mère']) : $this->pick(['Époux', 'Épouse', 'Frère', 'Sœur', 'Fils', 'Fille']),
            ]);
            if (mt_rand(1, 6) === 1) {
                [$allergen, $severity] = $this->pick(self::ALLERGIES);
                $patient->allergies()->create(['allergen' => $allergen, 'severity' => $severity, 'notes' => $severity === 'HIGH' ? 'Réaction anaphylactique documentée.' : null]);
            }
            if ($age >= 30 && mt_rand(1, 3) === 1) {
                $patient->medicalHistory()->create([
                    'condition' => $this->pick(self::HISTORY),
                    'start_date' => now()->subYears(mt_rand(1, 12))->toDateString(),
                    'notes' => null,
                ]);
            }
            $patients[] = $patient;
        }

        return $patients;
    }

    private function seedActivity(Clinic $clinic, array $staff, array $patients, array $labExams, array $imagingExams, array $medications, int $daysBack, bool $frozen): void
    {
        $doctors = $staff['DOCTOR'];
        $nurses = $staff['NURSE'] ?? [];
        $labTech = $staff['LABORATORY_TECHNICIAN'][0] ?? null;
        $radiologist = $staff['RADIOLOGIST'][0] ?? null;
        $pharmacist = $staff['PHARMACIST'][0] ?? null;
        $cashier = $staff['CASHIER'][0] ?? $staff['ADMIN_CLINIC'][0];
        $invoiceSequence = 0;
        $today = Carbon::today();
        $lastDay = $frozen ? -14 : 7;

        for ($offset = -$daysBack; $offset <= $lastDay; $offset++) {
            $day = $today->copy()->addDays($offset);
            // Closed on Sundays, except today so the demo always has a live waiting room.
            if ($day->isSunday() && !$day->isToday()) {
                continue;
            }
            $bookedPatients = [];
            foreach ($doctors as $doctor) {
                $slots = $this->pickMany(['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '15:00', '15:30', '16:00'], mt_rand(2, 6));
                sort($slots);
                foreach ($slots as $slot) {
                    $patient = $this->pickPatient($patients, $bookedPatients);
                    if (!$patient) {
                        break;
                    }
                    $bookedPatients[] = $patient->id;
                    $datetime = Carbon::parse($day->toDateString().' '.$slot);
                    $status = $datetime->isFuture()
                        ? 'SCHEDULED'
                        : $this->pickWeighted([['COMPLETED', 78], ['NO_SHOW', 12], ['CANCELLED', 10]]);
                    $isToday = $day->isToday();
                    $diagnosis = $this->diagnosisFor($patient);
                    // Today's upcoming patients: some are already in the waiting room.
                    $checkedIn = match (true) {
                        $status === 'COMPLETED' => $datetime->copy()->subMinutes(mt_rand(5, 25)),
                        $isToday && $status === 'SCHEDULED' && $datetime->lte(now()->addMinutes(90)) && mt_rand(1, 10) <= 6 => now()->subMinutes(mt_rand(5, 50)),
                        default => null,
                    };
                    $appointment = Appointment::create([
                        'clinic_id' => $clinic->id,
                        'patient_id' => $patient->id,
                        'doctor_id' => $doctor->id,
                        'appointment_datetime' => $datetime,
                        'status' => $status,
                        'reason' => mb_strimwidth($diagnosis[2], 0, 120, '…'),
                        'checked_in_at' => $checkedIn,
                        'called_at' => $status === 'COMPLETED' ? $datetime->copy()->addMinutes(mt_rand(0, 15)) : null,
                        'cancellation_reason' => $status === 'CANCELLED' ? $this->pick(['Patient en voyage', 'Patient hospitalisé ailleurs', 'Demande du patient', 'Médecin indisponible']) : null,
                        'created_at' => $datetime->copy()->subDays(mt_rand(1, 10)),
                    ]);
                    if ($status !== 'COMPLETED') {
                        if ($checkedIn && $nurses !== [] && mt_rand(0, 1) === 1) {
                            $this->seedVitals($clinic, $patient, null, $this->pick($nurses), $checkedIn->copy()->addMinutes(3), $diagnosis[8]);
                        }
                        continue;
                    }

                    $consultation = $this->seedConsultation($clinic, $patient, $doctor, $datetime, $diagnosis, $isToday, $appointment);
                    $nurse = $nurses !== [] ? $this->pick($nurses) : null;
                    if ($nurse) {
                        $this->seedVitals($clinic, $patient, $consultation, $nurse, $datetime, $diagnosis[8]);
                    }
                    $prescription = $this->seedPrescription($clinic, $consultation, $doctor, $medications, $diagnosis, $datetime, $isToday, $pharmacist);
                    $labRequest = $labTech ? $this->seedLabRequest($clinic, $consultation, $doctor, $labExams, $diagnosis, $datetime, $labTech, $nurse) : null;
                    $imagingRequest = $radiologist ? $this->seedImagingRequest($clinic, $consultation, $doctor, $imagingExams, $diagnosis, $datetime, $radiologist) : null;
                    if ($diagnosis[7] && $nurse) {
                        ConsultationTask::create([
                            'consultation_id' => $consultation->id,
                            'assigned_to' => $nurse->id,
                            'created_by' => $doctor->id,
                            'task_description' => $diagnosis[7],
                            'status' => $isToday ? 'PENDING' : 'COMPLETED',
                            'due_date' => $datetime->copy()->addHours(2),
                            'completed_by' => $isToday ? null : $nurse->id,
                            'completed_at' => $isToday ? null : $datetime->copy()->addHours(3),
                        ]);
                    }
                    if (!$isToday) {
                        $invoiceSequence++;
                        $this->seedInvoice($clinic, $patient, $consultation, $doctor, $labRequest, $imagingRequest, $prescription, $datetime, $invoiceSequence, $cashier, $labExams, $imagingExams);
                    }
                }
            }
        }

        // Closed cash registers for the past week (one small shortfall to show the follow-up).
        for ($daysAgo = 7; $daysAgo >= 1; $daysAgo--) {
            $day = $today->copy()->subDays($daysAgo);
            $payments = Payment::query()->where('clinic_id', $clinic->id)->where('received_by', $cashier->id)->whereDate('payment_datetime', $day)->get();
            if ($payments->isEmpty() || ($frozen && $daysAgo < 14)) {
                continue;
            }
            $cash = (float) $payments->where('payment_method', 'CASH')->sum('amount');
            $shortfall = $daysAgo === 3 ? 500 : 0;
            $closingId = DB::table('cash_closings')->insertGetId([
                'clinic_id' => $clinic->id,
                'cashier_id' => $cashier->id,
                'business_date' => $day->toDateString(),
                'payments_count' => $payments->count(),
                'expected_cash' => $cash,
                'expected_mobile_money' => (float) $payments->where('payment_method', 'MOBILE_MONEY')->sum('amount'),
                'expected_other' => 0,
                'counted_cash' => $cash - $shortfall,
                'difference' => -$shortfall,
                'notes' => $shortfall ? 'Écart constaté au comptage, signalé à la direction.' : null,
                'closed_at' => $day->copy()->setTime(18, 30),
                'created_at' => $day->copy()->setTime(18, 30),
                'updated_at' => $day->copy()->setTime(18, 30),
            ]);
            Payment::query()->whereIn('id', $payments->pluck('id'))->update(['cash_closing_id' => $closingId]);
        }

        // Two walk-in patients waiting today for the first doctor.
        if (!$frozen) {
            $queued = Appointment::query()->where('clinic_id', $clinic->id)->where('appointment_datetime', '>=', $today)->pluck('patient_id')->all();
            for ($walkIn = 0; $walkIn < 2; $walkIn++) {
                $patient = $this->pickPatient($patients, $queued);
                if (!$patient) {
                    break;
                }
                $queued[] = $patient->id;
                $arrived = now()->subMinutes(mt_rand(10, 70))->startOfMinute()->max($today->copy()->addMinute());
                $diagnosis = $this->diagnosisFor($patient);
                Appointment::create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctors[0]->id,
                    'appointment_datetime' => $arrived,
                    'status' => 'SCHEDULED',
                    'reason' => mb_strimwidth($diagnosis[2], 0, 120, '…'),
                    'is_walk_in' => true,
                    'checked_in_at' => $arrived,
                    'created_at' => $arrived,
                ]);
                if ($walkIn === 0 && $nurses !== []) {
                    $this->seedVitals($clinic, $patient, null, $this->pick($nurses), $arrived->copy()->addMinutes(15), $diagnosis[8]);
                }
            }
        }
    }

    private function seedConsultation(Clinic $clinic, Patient $patient, User $doctor, Carbon $datetime, array $diagnosis, bool $isToday, ?Appointment $appointment = null): Consultation
    {
        $status = $isToday ? $this->pick(['IN_PROGRESS', 'COMPLETED', 'DRAFT']) : 'SIGNED';
        $consultation = Consultation::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_id' => $appointment?->id,
            'consultation_datetime' => $datetime,
            'status' => $status,
            'created_at' => $datetime,
            'updated_at' => $datetime->copy()->addMinutes(25),
        ]);
        $sections = [
            [1, Consultation::SECTIONS[1], $diagnosis[2]],
            [3, Consultation::SECTIONS[3], $diagnosis[3]],
            [4, Consultation::SECTIONS[4], 'Tableau compatible avec : '.mb_strtolower($diagnosis[1]).'. Traitement ambulatoire.'],
            [7, Consultation::SECTIONS[7], 'Bien suivre le traitement. Revenir en cas d’aggravation ou de persistance des symptômes.'],
        ];
        foreach ($sections as [$number, $title, $content]) {
            $consultation->sections()->create(['section_number' => $number, 'title' => $title, 'content' => $content]);
        }
        $consultation->diagnostics()->create([
            'cim10_code' => $diagnosis[0],
            'cim10_description' => $diagnosis[1],
            'is_primary' => true,
        ]);
        if ($status === 'SIGNED') {
            $signedAt = $datetime->copy()->addMinutes(30);
            $consultation->digitalSignatures()->create([
                'user_id' => $doctor->id,
                'signature_hash' => hash_hmac('sha256', implode('|', [$consultation->id, $clinic->id, $patient->id, $doctor->id, $signedAt->toIso8601String()]), (string) config('app.key')),
                'signed_at' => $signedAt,
                'ip_address' => '10.0.0.'.mt_rand(2, 250),
                'user_agent' => 'Clinique+ (données de démonstration)',
            ]);
            // Adults with an acute illness often leave with a sick note.
            $age = Carbon::parse($patient->date_of_birth)->age;
            if ($diagnosis[8] === 'fever' && $age >= 6 && mt_rand(1, 3) === 1) {
                $this->seedCertificate($clinic, $patient, $doctor, $consultation, $age < 18 ? 'SCHOOL_ABSENCE' : 'WORK_STOPPAGE', $signedAt);
            }
        }

        return $consultation;
    }

    private function seedCertificate(Clinic $clinic, Patient $patient, User $doctor, Consultation $consultation, string $type, Carbon $issuedAt): void
    {
        $this->certificateSequence[$clinic->id] = ($this->certificateSequence[$clinic->id] ?? 0) + 1;
        $number = sprintf('CM-%s-%d-%05d', $clinic->code, $issuedAt->year, $this->certificateSequence[$clinic->id]);
        $start = $issuedAt->copy()->startOfDay();
        $end = $start->copy()->addDays(mt_rand(1, 4));
        $content = $type === 'SCHOOL_ABSENCE'
            ? 'Je soussigné(e), docteur en médecine, certifie que l’état de santé de l’élève susnommé(e) ne lui permet pas de fréquenter l’établissement scolaire pendant la période indiquée.'
            : 'Je soussigné(e), docteur en médecine, certifie avoir examiné ce jour le (la) patient(e) susnommé(e) et que son état de santé nécessite un arrêt de travail pour la période indiquée.';
        MedicalCertificate::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'consultation_id' => $consultation->id,
            'number' => $number,
            'type' => $type,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'content' => $content,
            'issued_at' => $issuedAt,
            'signature_hash' => hash_hmac('sha256', implode('|', [$number, $clinic->id, $patient->id, $doctor->id, $type, $start->toDateString(), $end->toDateString(), $content, $issuedAt->toIso8601String()]), (string) config('app.key')),
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ]);
    }

    private function seedVitals(Clinic $clinic, Patient $patient, ?Consultation $consultation, User $nurse, Carbon $datetime, string $profile): void
    {
        $age = Carbon::parse($patient->date_of_birth)->age;
        $child = $age < 12;
        [$temperature, $systolic, $diastolic, $heartRate, $spo2] = match ($profile) {
            'fever' => [round(mt_rand(382, 401) / 10, 1), mt_rand(100, 125), mt_rand(60, 80), mt_rand(95, 125), mt_rand(93, 98)],
            'hypertension' => [round(mt_rand(365, 372) / 10, 1), mt_rand(150, 185), mt_rand(95, 110), mt_rand(72, 95), mt_rand(96, 99)],
            'mild' => [round(mt_rand(368, 379) / 10, 1), mt_rand(105, 130), mt_rand(65, 85), mt_rand(70, 100), mt_rand(96, 99)],
            default => [round(mt_rand(362, 374) / 10, 1), mt_rand(105, 135), mt_rand(65, 85), mt_rand(65, 90), mt_rand(97, 100)],
        };
        VitalSign::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'consultation_id' => $consultation?->id,
            'recorded_by' => $nurse->id,
            'systolic_bp' => $child ? null : $systolic,
            'diastolic_bp' => $child ? null : $diastolic,
            'heart_rate' => $child ? $heartRate + 15 : $heartRate,
            'respiratory_rate' => $child ? mt_rand(22, 34) : mt_rand(14, 22),
            'temperature' => $temperature,
            'spo2' => $spo2,
            'weight_kg' => $child ? round(3 + $age * 2.6 + mt_rand(0, 30) / 10, 1) : mt_rand(48, 92),
            'height_cm' => $age >= 16 ? mt_rand(152, 188) : null,
            'recorded_at' => $datetime->copy()->subMinutes(10),
        ]);
    }

    private function seedPrescription(Clinic $clinic, Consultation $consultation, User $doctor, array $medications, array $diagnosis, Carbon $datetime, bool $isToday, ?User $pharmacist): ?Prescription
    {
        if ($diagnosis[6] === []) {
            return null;
        }
        // Recent prescriptions may still be waiting at the pharmacy; older ones were collected.
        $status = match (true) {
            $isToday => 'DRAFT',
            $datetime->diffInDays(now()) <= 2 => $this->pickWeighted([['DISPENSED', 50], ['SIGNED', 50]]),
            default => $this->pickWeighted([['DISPENSED', 95], ['SIGNED', 5]]),
        };
        $prescription = Prescription::create([
            'clinic_id' => $clinic->id,
            'consultation_id' => $consultation->id,
            'doctor_id' => $doctor->id,
            'prescribed_at' => $datetime->copy()->addMinutes(20),
            'status' => $status,
            'notes' => 'Respecter les doses et la durée du traitement.',
            'created_at' => $datetime->copy()->addMinutes(20),
            'updated_at' => $status === 'DISPENSED' ? $datetime->copy()->addHours(1) : $datetime->copy()->addMinutes(20),
        ]);
        foreach ($diagnosis[6] as [$name, $dosage, $frequency, $days, $quantity]) {
            $prescription->items()->create([
                'medication_id' => $medications[$name]->id,
                'dosage_prescribed' => $dosage,
                'frequency' => $frequency,
                'duration_days' => $days,
                'quantity' => $quantity,
            ]);
        }

        return $prescription;
    }

    private function seedLabRequest(Clinic $clinic, Consultation $consultation, User $doctor, array $labExams, array $diagnosis, Carbon $datetime, User $labTech, ?User $nurse): ?LabRequest
    {
        $exams = array_values(array_filter(array_map(fn (string $name) => $labExams[$name] ?? null, $diagnosis[4])));
        if ($exams === []) {
            return null;
        }
        $ageInDays = $datetime->diffInDays(now());
        // Today's requests are spread over the whole lab workflow for the demo worklist.
        $status = match (true) {
            $ageInDays < 1 => $this->pickWeighted([['PENDING', 35], ['RECEIVED', 25], ['IN_PROGRESS', 20], ['READY', 20]]),
            $ageInDays < 2 => 'READY',
            default => 'SIGNED',
        };
        $collector = $nurse ?? $labTech;
        $collectedAt = $datetime->copy()->addMinutes(30);
        $this->sampleSequence[$clinic->id.$collectedAt->format('ymd')] = ($this->sampleSequence[$clinic->id.$collectedAt->format('ymd')] ?? 0) + 1;
        $request = LabRequest::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $consultation->patient_id,
            'doctor_id' => $doctor->id,
            'consultation_id' => $consultation->id,
            'requested_at' => $datetime->copy()->addMinutes(15),
            'status' => $status,
            'priority' => $diagnosis[0] === 'B50.0' ? 'URGENT' : 'ROUTINE',
            'clinical_info' => mb_strimwidth($diagnosis[2], 0, 150, '…'),
            'sample_number' => $status === 'PENDING' ? null : sprintf('L%d-%s-%04d', $clinic->id, $collectedAt->format('ymd'), $this->sampleSequence[$clinic->id.$collectedAt->format('ymd')]),
            'created_at' => $datetime->copy()->addMinutes(15),
        ]);
        foreach ($exams as $exam) {
            DB::table('lab_request_exams')->insert(['lab_request_id' => $request->id, 'lab_exam_id' => $exam->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($status === 'PENDING') {
            return $request;
        }
        DB::table('lab_samples')->insert([
            'request_id' => $request->id,
            'sample_type' => $exams[0]->sample_type ?? 'Sang veineux',
            'collected_at' => $collectedAt,
            'collected_by' => $collector->id,
            'created_at' => $collectedAt,
            'updated_at' => $collectedAt,
        ]);
        if (in_array($status, ['RECEIVED', 'IN_PROGRESS'], true)) {
            return $request;
        }
        foreach ($exams as $exam) {
            $value = $this->labValue($exam->name, $diagnosis[0]);
            $flag = LabExam::flagFor($value, $exam->reference_min, $exam->reference_max)
                ?? (str_starts_with($value, 'Positive') || str_contains($value, 'coli') ? 'ABNORMAL' : null);
            DB::table('lab_results')->insert([
                'request_id' => $request->id,
                'exam_id' => $exam->id,
                'result_value' => $value,
                'unit' => $exam->unit,
                'reference_min' => $exam->reference_min,
                'reference_max' => $exam->reference_max,
                'flag' => $flag,
                'entered_by' => $labTech->id,
                'status' => $status === 'SIGNED' ? 'SIGNED' : 'DRAFT',
                'validator_id' => $status === 'SIGNED' ? $labTech->id : null,
                'validated_at' => $status === 'SIGNED' ? $datetime->copy()->addHours(4) : null,
                'created_at' => $datetime->copy()->addHours(3),
                'updated_at' => $datetime->copy()->addHours(4),
            ]);
        }

        return $request;
    }

    private function seedImagingRequest(Clinic $clinic, Consultation $consultation, User $doctor, array $imagingExams, array $diagnosis, Carbon $datetime, User $radiologist): ?ImagingRequest
    {
        $examName = $diagnosis[5][0] ?? null;
        if (!$examName || !isset($imagingExams[$examName])) {
            return null;
        }
        $done = $datetime->diffInDays(now()) >= 1;
        $request = ImagingRequest::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $consultation->patient_id,
            'doctor_id' => $doctor->id,
            'exam_id' => $imagingExams[$examName]->id,
            'consultation_id' => $consultation->id,
            'requested_at' => $datetime->copy()->addMinutes(15),
            'status' => $done ? 'SIGNED' : $this->pick(['PENDING', 'IN_PROGRESS']),
            'clinical_info' => mb_strimwidth($diagnosis[3], 0, 150, '…'),
            'created_at' => $datetime->copy()->addMinutes(15),
        ]);
        if ($done) {
            DB::table('imaging_reports')->insert([
                'request_id' => $request->id,
                'radiologist_id' => $radiologist->id,
                'report_text' => match ($examName) {
                    'Radiographie du thorax (face)' => "Opacité alvéolaire du lobe inférieur droit, compatible avec une pneumopathie. Pas d’épanchement pleural. Silhouette cardiaque normale.\nConclusion : pneumopathie lobaire inférieure droite.",
                    'Échographie obstétricale' => "Grossesse monofœtale évolutive, activité cardiaque présente. Biométrie conforme au terme. Placenta antérieur normalement inséré. Liquide amniotique en quantité normale.\nConclusion : grossesse évolutive sans anomalie décelée.",
                    default => "Foie, vésicule, voies biliaires, pancréas, rate et reins d’aspect normal. Pas d’épanchement.\nConclusion : échographie abdominale sans anomalie.",
                },
                'signed_at' => $datetime->copy()->addHours(5),
                'created_at' => $datetime->copy()->addHours(4),
                'updated_at' => $datetime->copy()->addHours(5),
            ]);
        }

        return $request;
    }

    private function seedInvoice(Clinic $clinic, Patient $patient, Consultation $consultation, User $doctor, ?LabRequest $labRequest, ?ImagingRequest $imagingRequest, ?Prescription $prescription, Carbon $datetime, int $sequence, User $cashier, array $labExams, array $imagingExams): void
    {
        $specialist = ($doctor->metadata['specialty'] ?? 'Médecine générale') !== 'Médecine générale';
        $lines = [['Consultation '.($specialist ? 'spécialisée' : 'de médecine générale'), $specialist ? 15000 : 10000, 'consultation:'.$consultation->id]];
        if ($labRequest) {
            foreach (DB::table('lab_request_exams')->where('lab_request_id', $labRequest->id)->pluck('lab_exam_id') as $examId) {
                $exam = collect($labExams)->firstWhere('id', $examId);
                $lines[] = [$exam->name, $exam->reference_values['price'] ?? 3000, 'lab:'.$labRequest->id];
            }
        }
        if ($imagingRequest) {
            $exam = collect($imagingExams)->firstWhere('id', $imagingRequest->exam_id);
            $price = collect(self::IMAGING_EXAMS)->firstWhere(1, $exam->name)[2] ?? 15000;
            $lines[] = [$exam->name, $price, 'imaging:'.$imagingRequest->id];
        }
        if ($prescription?->status === 'DISPENSED') {
            $prescription->load('items.medication');
            foreach ($prescription->items as $item) {
                $lines[] = ['Pharmacie : '.$item->medication->name.' × '.$item->quantity, $item->medication->price * $item->quantity, 'prescription:'.$prescription->id];
            }
        }
        $total = array_sum(array_column($lines, 1));
        $age = $datetime->diffInDays(now());
        $status = match (true) {
            $age <= 2 => $this->pickWeighted([['PAID', 55], ['ISSUED', 45]]),
            default => $this->pickWeighted([['PAID', 88], ['OVERDUE', 12]]),
        };
        $issuedAt = $datetime->copy()->addMinutes(45);
        $invoice = Invoice::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'invoice_number' => sprintf('%s-%d-%06d', $clinic->code, $issuedAt->year, $sequence),
            'subtotal' => $total,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total_amount' => $total,
            'status' => $status,
            'issued_at' => $issuedAt,
            'paid_at' => $status === 'PAID' ? $issuedAt->copy()->addMinutes(10) : null,
            'due_date' => $issuedAt->copy()->addDays(3)->endOfDay(),
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ]);
        foreach ($lines as [$description, $amount, $reference]) {
            $invoice->items()->create(['description' => $description, 'reference_id' => $reference, 'amount' => $amount, 'tax_amount' => 0]);
        }
        if ($status === 'PAID') {
            $mobile = mt_rand(1, 10) <= 4;
            Payment::create([
                'clinic_id' => $clinic->id,
                'invoice_id' => $invoice->id,
                'amount' => $total,
                'payment_method' => $mobile ? 'MOBILE_MONEY' : 'CASH',
                'payment_reference' => $mobile ? 'OM'.$issuedAt->format('ymdHi').mt_rand(10, 99) : null,
                'payment_datetime' => $issuedAt->copy()->addMinutes(10),
                'received_by' => $cashier->id,
                'status' => 'CONFIRMED',
            ]);
        }
    }

    private function diagnosisFor(Patient $patient): array
    {
        $age = Carbon::parse($patient->date_of_birth)->age;
        $candidates = array_values(array_filter(self::DIAGNOSES, function (array $diagnosis) use ($patient, $age) {
            return match ($diagnosis[0]) {
                'Z34.9' => $patient->gender === 'F' && $age >= 18 && $age <= 40,
                'I10', 'E11.9' => $age >= 35,
                default => true,
            };
        }));
        $weights = ['B50.9' => 30, 'B50.0' => 4, 'J06.9' => 18, 'J18.9' => 7, 'A09' => 12, 'I10' => 9, 'E11.9' => 6, 'N39.0' => 6, 'K29.7' => 5, 'Z34.9' => 10, 'D57.0' => 3];

        return $this->pickWeighted(array_map(fn (array $diagnosis) => [$diagnosis, $weights[$diagnosis[0]]], $candidates));
    }

    private function labValue(string $examName, string $diagnosisCode): string
    {
        $malaria = str_starts_with($diagnosisCode, 'B50');

        return match ($examName) {
            'Goutte épaisse (paludisme)' => $malaria ? 'Positive — '.number_format(mt_rand(800, $diagnosisCode === 'B50.0' ? 250000 : 40000), 0, ',', ' ').' parasites/µL' : 'Négative',
            'Numération formule sanguine (NFS)' => number_format(mt_rand($malaria || $diagnosisCode === 'D57.0' ? 72 : 112, $malaria || $diagnosisCode === 'D57.0' ? 105 : 150) / 10, 1, ',', ''),
            'Glycémie à jeun' => number_format(mt_rand($diagnosisCode === 'E11.9' ? 135 : 75, $diagnosisCode === 'E11.9' ? 210 : 105) / 100, 2, ',', ''),
            'Créatinine' => (string) mt_rand(7, $diagnosisCode === 'B50.0' ? 22 : 12),
            'Protéine C réactive (CRP)' => (string) mt_rand($diagnosisCode === 'J18.9' ? 40 : 2, $diagnosisCode === 'J18.9' ? 160 : 8),
            'Examen cytobactériologique des urines (ECBU)' => (string) mt_rand(50, 400).' — Escherichia coli',
            'Groupe sanguin et rhésus' => $this->pick(['O+', 'A+', 'B+', 'AB+', 'O-', 'A-']),
            default => (string) mt_rand(10, 35),
        };
    }

    private function pickPatient(array $patients, array $alreadyBooked): ?Patient
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $patient = $this->pick($patients);
            if (!in_array($patient->id, $alreadyBooked, true)) {
                return $patient;
            }
        }

        return null;
    }

    private function event(Clinic $clinic, string $type, string $description, Carbon $at): void
    {
        SubscriptionEvent::create(['clinic_id' => $clinic->id, 'type' => $type, 'description' => $description, 'created_at' => $at]);
    }

    private function phone(): string
    {
        return sprintf('+235 %d%d %02d %02d %02d', $this->pick([6, 9]), mt_rand(0, 9), mt_rand(0, 99), mt_rand(0, 99), mt_rand(0, 99));
    }

    /** @return array{string, string} */
    private function splitName(string $name): array
    {
        $parts = explode(' ', preg_replace('/^Dr\s+/', '', $name));

        return [array_shift($parts), implode(' ', $parts)];
    }

    private function ascii(string $value): string
    {
        return preg_replace('/[^A-Za-z.]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value);
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function pickMany(array $items, int $count): array
    {
        $keys = array_rand($items, min($count, count($items)));

        return array_map(fn ($key) => $items[$key], (array) $keys);
    }

    /** @param list<array{mixed, int}> $weighted */
    private function pickWeighted(array $weighted): mixed
    {
        $roll = mt_rand(1, array_sum(array_column($weighted, 1)));
        foreach ($weighted as [$value, $weight]) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        return $weighted[0][0];
    }
}
