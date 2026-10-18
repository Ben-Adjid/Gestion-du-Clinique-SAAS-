<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function forgot(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        Password::sendResetLink(['email' => $validated['email']]);

        return response()->json([
            'message' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d’être envoyé. Il expire dans 24 heures.',
        ]);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $broker = Password::broker();
        $user = $broker->getUser(['email' => $validated['email']]);
        if (!$user instanceof User || !$broker->tokenExists($user, $validated['token'])) {
            return response()->json(['message' => 'Ce lien de réinitialisation est invalide ou a expiré.'], 422);
        }
        if ($user->usedPasswordRecently($validated['password'])) {
            return response()->json([
                'message' => 'Ce mot de passe a déjà été utilisé récemment.',
                'errors' => ['password' => ['Choisissez un mot de passe différent de vos 5 derniers.']],
            ], 422);
        }

        $status = $broker->reset($validated, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Ce lien de réinitialisation est invalide ou a expiré.'], 422);
        }

        return response()->json(['message' => 'Votre mot de passe a été modifié. Vous pouvez vous connecter.']);
    }
}
