<?php

declare(strict_types=1);

namespace App\Filament\Auth\MfaApper;

use App\Filament\Auth\MfaApperAuthentication;
use App\Models\MfaApp;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * «Gi nytt navn» for én app på profilsiden (O15). App-ID-en bindes når profilsiden bygges, og slås
 * alltid opp blant den innloggede brukerens egne apper.
 */
class GiNyttNavnMfaAppAction
{
    public static function make(MfaApperAuthentication $mfa, int $appId): Action
    {
        return Action::make('giNyttNavnMfaApp')
            ->label('Gi nytt navn')
            ->color('gray')
            ->icon(Heroicon::PencilSquare)
            ->link()
            ->modalWidth(Width::Medium)
            ->modalHeading('Gi appen nytt navn')
            ->fillForm(fn (): array => ['navn' => $mfa->egenApp($appId)?->navn])
            ->schema(fn (): array => [
                TextInput::make('navn')
                    ->label('Navn på appen')
                    ->required()
                    ->maxLength(MfaApp::NAVN_MAKS)
                    ->regex(MfaApp::NAVN_REGEX)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($mfa, $appId): void {
                        $app = $mfa->egenApp($appId);

                        if (is_string($value) && $app && LeggTilMfaAppAction::navnErBrukt($mfa->bruker(Filament::auth()->user()), $value, $app->getKey())) {
                            $fail('Du har allerede en app med dette navnet.');
                        }
                    }),
            ])
            ->modalSubmitAction(fn (Action $action) => $action->label('Lagre'))
            ->action(function (Action $action, array $data) use ($mfa, $appId): void {
                $app = $mfa->egenApp($appId);

                if (! $app) {
                    return;
                }

                try {
                    $app->update(['navn' => trim((string) $data['navn'])]);
                } catch (UniqueConstraintViolationException) {
                    // Databasen kan regne navn som like der PHP ikke gjør det (aksenter, ß/ss i MySQL).
                    Notification::make()->title('Du har allerede en app med dette navnet.')->danger()->send();
                    $action->halt();
                }

                Notification::make()
                    ->title('Navnet er endret')
                    ->success()
                    ->send();

                $mfa->lastSidenPaNytt($action);
            });
    }
}
