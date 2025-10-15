<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserRoleRight;
use App\Services\SudirOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Session;

class AuthController extends Controller
{

    public function __construct(
        protected SudirOAuthService $oauthService
    ) {}

    /**
     * Выполняет аутентификацию пользователя по email и паролю
     * Создает токен доступа с сроком действия 24 часа
     * Сохраняет в кэше права доступа и идентификаторы объектов для пользователя
     * Удаляет предыдущие токены пользователя перед созданием нового
     */
    public function login(Request $request)
    {
        try {
            // Валидация с явным возвратом ошибок
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'password' => ['required'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Ошибка валидации. Требуется email + пароль',
                    'errors' => $validator->errors()
                ], 422);
            }

            $credentials = $request->only('email', 'password');

            if (Auth::attempt($credentials)) {
                $user = Auth::user();
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

                return response()->json([
                    'status' => true,
                    'message' => 'Успешный вход в систему',
                    'token' => $token,
                    'raiting' => $user->access_to_raiting,
                ]);
            }

            return response()->json([
                'status' => false,
                'message' => 'Неверный email или пароль'
            ], 401);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Выполняет выход пользователя из системы
     * Удаляет все токены пользователя и определяет URL для перенаправления
     * Для OAuth пользователей генерирует URL выхода из СУДИР
     * Для локальных пользователей возвращает URL страницы авторизации
     */
    public function logout(Request $request)
    {
        try {
            $redirectLink = $this->processLogoutAndGetRedirectLink($request);
            $request->user()->tokens()->delete();

            return response()->json(
                [
                    'message' => 'Выход выполнен успешно',
                    'redirectLink' => $redirectLink,
                ], 200
            );

        } catch (\Throwable $th) {
            report($th);
            return response()->json([
                'error' => 'Произошла ошибка сервера при вызове метода logout',
            ], 500);
        }
    }

    /**
     * Определяет URL для перенаправления после выхода
     * Для OAuth пользователей генерирует URL логаута СУДИР с id_token
     * Для локальных пользователей возвращает URL страницы авторизации приложения
     * Выполняет выход из текущей сессии Laravel
     */
    protected function processLogoutAndGetRedirectLink(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        Auth::logout();

        // Если был OAuth-пользователь — инициируем логаут в СУДИР
        if ($user && $user->oauth_id_token) {
            $oAuthService = app(SudirOAuthService::class);
            $logoutUrl = $oAuthService->getLogoutUrl();

            return $logoutUrl;
        }

        // Если не OAuth — просто редирект на страницу авторизации
        return config('app.url').'/auth';
    }



    /**
     * Обрабатывает callback от СУДИР после завершения выхода
     * Проверяет state параметр для защиты от CSRF атак
     * Перенаправляет пользователя на страницу авторизации приложения
     */
    protected function handleSudirLogoutCallback(): RedirectResponse
    {
        $state = request('state');

        // Проверка state — защита от CSRF
        $storedState = Session::pull('oauth_logout_state'); // сразу удаляем
        if (!$storedState || $state !== $storedState) {
            return redirect('/auth')->withErrors('Неверный запрос выхода.');
        }

        return redirect('/auth');
    }

    /**
     * Проверяет возможность отображения кнопки входа через СУДИР
     * Определяет доступность сервиса СУДИР для текущего домена приложения
     * Возвращает true если СУДИР доступен для использования
     */
    public function isShowSudirLoginButton()
    {
        try {
            $oAuthService = app(SudirOAuthService::class);
            $showButton = $oAuthService->isUseSudirService();
            $loginLink = $oAuthService->getAuthorizationUrl();

            if (!$showButton) {
                return response()->json([
                    'status' => true,
                    'showSudirButton' => false,
                    'sudirLoginLink' => null
                ]);
            }

            return response()->json([
                'status' => true,
                'showSudirButton' => true,
                'sudirLoginLink' => $loginLink
            ]);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'status' => false,
                'showSudirButton' => false,
                'sudirLoginLink' => null,
                'error' => 'Произошла ошибка при проверке доступности СУДИР'
            ], 500);
        }
    }

    /**
     * Генерирует хэш пароля с использованием встроенной функции Laravel Hash
     * Используется для тестирования или служебных целей
     */
    public function generateHash($pass)
    {
        try {
            return Hash::make($pass);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }
}
