<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\OAuthException;
use App\Models\User;
use App\Models\UserRoleRight;
use App\Models\UserSudirLogData;
use App\Services\SudirOAuthService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OAuthController
{
    public function __construct(
        protected SudirOAuthService $oauthService
    ) {}


    /**
     * Обрабатывает callback от СУДИР после успешной авторизации
     * Получает код и state, обменивает код на токены, ищет/создает пользователя
     * Выполняет вход пользователя в систему и перенаправляет на целевую страницу
     */
    public function handleProviderCallback(Request $request): \Illuminate\Http\JsonResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');

        try {
            if (! $code) {
                throw new \Exception('Authorization code is missing');
            }

            $result = $this->oauthService->handleCallback($code, $state);

            $user = $this->findUser(
                $result['user'],
                $result['id_token'],
                $result['access_token'],
                $result['refresh_token'],
                $result['expires_in']
            );

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Не найден пользователь с таким email.',
                    'token' => null,
                    'raiting' => null,
                ], 404);
            }

            $this->updateSudirData($user, $result['user']);

            Auth::login($user, true);

            // копия процедуры авторизации пользователя и выдачи токена, но наша версия без проверки пароля

            // Удаляем все старые токены пользователя
            $user->tokens()->delete();

            // Создаем дату истечения токена (текущее время + 24 часа)
            $expiresAt = Carbon::now()->addHours(24);
            // Создаем новый токен
            $token = $user->createToken('auth-token',['*'],$expiresAt)->plainTextToken;

            $objectsIDSAllowedToReadForUser = UserRoleRight::getAllowedObjectToReadIDS($user->id);

//          Как и токен, данный Кэш умирает через 2 часа - эдакий аналог Сессии у пользователя
            cache()->put('cacheObjectsIDSAllowedToReadForUser_'.$token, $objectsIDSAllowedToReadForUser, 3600*24);
            cache()->put('cacheActivatedUserID_'.$token, $user->id, 3600*24);
            cache()->put('cacheRaitingAccess_'.$token, $user->access_to_raiting, 3600*24);
            cache()->put('cacheLastActivity_'.$token, now()->toDateTimeString(), 3600*24);

//            todo: придумать, как передать авотризацияонные данные на фронт
            return response()->json([
                'status' => true,
                'message' => 'Успешный вход в систему',
                'token' => $token,
                'raiting' => $user->access_to_raiting,
            ]);


            } catch (OAuthException $e) {
                Log::error('OAuth callback failed', [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                $message = 'Авторизация не удалась. Попробуйте снова.';

                // Обработка для ошибки state (csrf)
                if ($e->getCode() === 403) {
                    $message = 'Ошибка безопасности при авторизации.';
                }

                return response()->json([
                    'status' => false,
                    'message' => $message,
                    'token' => null,
                    'raiting' => null,
                ], 400);

            } catch (Exception $e) {
                Log::error('Unexpected error during OAuth callback', ['error' => $e->getMessage()]);

                return response()->json([
                    'status' => false,
                    'message' => 'Произошла непредвиденная ошибка.',
                    'token' => null,
                    'raiting' => null,
                ], 500);
            }
    }

    /**
     * Ищет пользователя по email из данных СУДИР и обновляет OAuth токены
     * Если пользователь не найден, возвращает null
     * Обновляет токены и время их истечения в базе данных
     */
    protected function findUser(array $oauthUser, string $idToken, string $accessToken, ?string $refreshToken, ?int $expiresIn): ?User
    {
        $user = User::query()->where('email', $oauthUser['email'])->first();

        $expiresAt = $expiresIn ? now()->addSeconds($expiresIn) : null;

        if (! $user) {
            return null;
        }

        $user->update([
            'oauth_id_token' => $idToken,
            'oauth_access_token' => $accessToken,
            'oauth_refresh_token' => $refreshToken,
            'oauth_expires_at' => $expiresAt,
        ]);

        return $user;
    }

    /**
     * Создает запись в таблице users_sudir_log_data с информацией о сессии СУДИР
     * Сохраняет данные пользователя полученные от СУДИР для аудита и анализа
     */
    protected function updateSudirData(User $user, array $sudirData): void
    {
        $UserSudirLogData = new UserSudirLogData([
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
        $UserSudirLogData->fillFromOAuthData($sudirData);
        $UserSudirLogData->save();
    }
}
