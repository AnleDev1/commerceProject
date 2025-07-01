<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use App\Models\Image;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    protected $cloudinary;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary();
    }

    public function register(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|min:2|max:10',
                'email' => 'required|email|string|min:10|max:75|unique:users',
                'password' => 'required|string|min:10|confirmed',
                'image' => 'nullable|image|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            // Crear usuario
            $user = User::create([
                'name' => $request->get('name'),
                'role' => 'user',
                'password' => bcrypt($request->get('password')),
                'email' => $request->get('email'),
            ]);

            $image = null;

            if ($request->hasFile('image')) {
                $uploadedFileUrl = $this->cloudinary->uploadApi()->upload(
                    $request->file('image')->getRealPath(),
                    ['folder' => 'usuarios/' . $user->id]
                );

                $image = new Image([
                    'public_id' => $uploadedFileUrl['public_id'],
                    'url' => $uploadedFileUrl['secure_url'],
                ]);

                $user->images()->save($image);
            }

            // Crear access token para el usuario recién registrado
            $token = auth()->login($user);

            // Crear refresh token aleatorio
            $refreshToken = Str::random(64);

            // Guardar refresh token en BD con expiración y sin revocar
            RefreshToken::create([
                'user_id' => $user->id,
                'token' => $refreshToken,
                'expires_at' => now()->addDays(14),
                'revoked' => false,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Usuario creado correctamente',
                'user_id' => $user->id,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'image_url' => $image ? $image->url : null,
            ], 201)->cookie(
                'refresh_token',
                $refreshToken,
                60 * 24 * 14, // 14 días en minutos
                '/',
                null,
                false,  // Cambiar a true en producción si usas HTTPS
                true,   // HttpOnly para proteger contra acceso JS
                false,
                'Strict'
            );
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => 'No se pudo registrar el usuario', 'error' => $e->getMessage()], 500);
        }
    }


    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|min:10|max:75',
            'password' => 'required|string|min:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $credentials = $request->only(['email', 'password']);

        try {
            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json(['message' => 'Credenciales inválidas'], 401);
            }

            $user = auth()->user();

            // Crear refresh token aleatorio
            $refreshToken = Str::random(64);

            // Guardar refresh token en la base de datos con expiración (14 días)
            RefreshToken::create([
                'user_id' => $user->id,
                'token' => $refreshToken,
                'expires_at' => now()->addDays(14),
                'revoked' => false,
            ]);

            // Retorna el access token en JSON y el refresh token en cookie segura HttpOnly
            return response()
                ->json([
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                ])
                ->cookie(
                    'refresh_token',
                    $refreshToken,
                    60 * 24 * 14, // 14 días en minutos
                    '/',
                    null,
                    false,  // Cambiar a true en producción si usas HTTPS
                    true,   // HttpOnly para evitar acceso por JS
                    false,
                    'Strict'
                );
        } catch (JWTException $exception) {
            return response()->json(['error' => 'No se pudo generar el token', 'details' => $exception->getMessage()], 500);
        }
    }



    public function getUser()
    {
        $user = Auth::user();
        $image = $user->images->first();

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'image_url' => $image ? $image->url : null
        ], 200);
    }


    public function logout(Request $request)
    {
        try {
            // Invalida el access token JWT (
            JWTAuth::invalidate(JWTAuth::getToken());

            // Intenta obtener el refresh token desde la cookie
            $refreshToken = $request->cookie('refresh_token');

            if ($refreshToken) {
                $tokenRecord = RefreshToken::where('token', $refreshToken)
                    ->where('revoked', false)
                    ->where('expires_at', '>', now())
                    ->first();

                // Si lo encuentra, lo revoca
                if ($tokenRecord) {
                    $tokenRecord->update(['revoked' => true]);
                }
            }

            // Limpia la cookie del refresh token en el navegador
            return response()
                ->json(['message' => 'Desconectado correctamente'])
                ->cookie('refresh_token', '', -1, '/', null, false, true, false, 'Strict');
        } catch (JWTException $exception) {
            return response()->json([
                'error' => 'No se pudo cerrar la sesión',
            ], 500);
        }
    }


    public function userUpdate(Request $request)
    {
        // Iniciamos una transacción para asegurar que todos los cambios se apliquen juntos
        DB::beginTransaction();

        try {

            // Obtenemos el usuario autenticado
            $user = Auth::user();

            // Validamos los datos recibidos; los campos son opcionales pero si llegan deben cumplir reglas
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|min:2|max:100',
                'email' => 'sometimes|string|email|min:10|max:75|unique:users,email,' . $user->id,
                'password' => 'sometimes|string|min:10|confirmed',
                'image' => 'sometimes|image|max:2048',
            ]);

            // Si la validación falla, devolvemos errores 
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->has('name')) {
                $user->name = $request->name;
            }

            if ($request->has('email')) {
                $user->email  = $request->email;
            }

            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
            }


            // Guardamos los cambios en la base de datos
            $user->save();

            // Verificamos si el request incluye un archivo de imagen
            if ($request->hasFile('image')) {
                try {
                    // Obtenemos la imagen anterior (si existe) usando la relación polimórfica
                    $oldImage = optional($user->images->first());

                    // Si hay una imagen anterior con public_id, la eliminamos de Cloudinary y de la base de datos
                    if ($oldImage->public_id) {
                        $this->cloudinary->uploadApi()->destroy($oldImage->public_id);
                        $oldImage->delete();
                    }

                    // Subimos la nueva imagen a Cloudinary, en una carpeta específica del usuario
                    $uploadedFileUrl = $this->cloudinary->uploadApi()->upload(
                        $request->file('image')->getRealPath(),
                        [
                            'folder' => 'usuarios/' . $user->id
                        ]
                    );

                    // Creamos la instancia del modelo Image con la info devuelta por Cloudinary
                    $image = new Image([
                        'public_id' => $uploadedFileUrl['public_id'],  // ID único de Cloudinary
                        'url' => $uploadedFileUrl['secure_url']  // URL segura para acceder a la imagen
                    ]);

                    // Asociamos y guardamos la nueva imagen al usuario mediante relación polimórfica
                    $user->images()->save($image);
                } catch (\Throwable $th) {
                    // Si algo falla al subir la imagen, lo registramos en el log pero no interrumpimos el resto de la operación
                    Log::error('Error subiendo imagen: ' . $th->getMessage());
                }
            }

            DB::commit();
            return response()->json(['message' => 'Datos Actualizados']);
        } catch (\Throwable $th) {
            DB::rollback();
            return response()->json(['message' => 'No se pudieron actualizar los datos ', 'error' => $th->getMessage()], 500);
        }
    }




    public function refresh(Request $request)
    {
        try {
            // 1. Obtener el refresh token de la cookie HttpOnly
            $refreshToken = $request->cookie('refresh_token');

            if (!$refreshToken) {
                return response()->json(['error' => 'No se proporcionó refresh token'], 401);
            }

            // 2. Buscar el refresh token válido en la base de datos (no revocado y no expirado)
            $tokenRecord = RefreshToken::where('token', $refreshToken)
                ->where('revoked', false)
                ->where('expires_at', '>', now())
                ->first();

            if (!$tokenRecord) {

                return  $this->logout($request);
            }

            // 3. Marcar el refresh token actual como revocado (rotación)
            $tokenRecord->update(['revoked' => true]);

            // 4. Generar un nuevo access token para el usuario
            $newAccessToken = JWTAuth::fromUser($tokenRecord->user);

            // 5. Crear nuevo refresh token aleatorio
            $newRefreshToken = Str::random(64);

            // 6. Guardar el nuevo refresh token en BD con expiración
            RefreshToken::create([
                'user_id' => $tokenRecord->user_id,
                'token' => $newRefreshToken,
                'expires_at' => now()->addDays(14),
                'revoked' => false,
            ]);

            // 7. Devolver el nuevo access token y establecer cookie HttpOnly con el nuevo refresh token
            return response()
                ->json([
                    'access_token' => $newAccessToken,
                    'token_type' => 'Bearer',
                ])
                ->cookie('refresh_token', $newRefreshToken, 60 * 24 * 14, '/', null, false, true, false, 'Strict');
        } catch (JWTException $e) {
            return $this->logout($request);
            return response()->json([
                'error' => 'Error al refrescar el token',
                'message' => $e->getMessage(),
            ], 401);
        }
    }
}
