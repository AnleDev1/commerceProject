<?php

namespace App\Http\Controllers;

use App\Models\Image;
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
        //Iniciamos la transacción para verificar que todos los cambios se apliquen juntos
        DB::beginTransaction();
        try {

            $validator =  Validator::make($request->All(), [
                'name' => 'required|string|min:2|max:10',
                'email' => 'required|email|string|min:10|max:75|unique:users',
                'password' => 'required|string|min:10|confirmed',
                'image' => 'nullable|image|max:2048',
            ]);

            //Si la validacion falla, devolvemos el errores
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            //Creamos el usuario con los datos validados
            $user = User::create([
                'name' => $request->get('name'),
                'role' => 'user',
                'password' =>  bcrypt($request->get('password')),
                'email' => $request->get('email')
            ]);

            $image = null;

            // Verificamos si el request incluye un archivo de imagen
            if ($request->hasFile('image')) {

                // Subimos la imagen a Cloudinary, especificando la carpeta con el ID del usuario
                $uploadedFileUrl = $this->cloudinary->uploadApi()->upload(
                    $request->file('image')->getRealPath(),
                    [
                        'folder' => 'usuarios/' . $user->id
                    ]
                );

                // Creamos un nuevo registro de imagen con los datos devueltos por Cloudinary
                $image = new Image([
                    'public_id' => $uploadedFileUrl['public_id'], // Identificador único en Cloudinary
                    'url' => $uploadedFileUrl['secure_url']    // URL segura para acceder a la imagen
                ]);

                // Asociamos esta imagen al usuario guardándola en la relación 'images'
                $user->images()->save($image);
            }


            $token = auth()->login($user);


            // Devolvemos respuesta exitosa con datos del nuevo usuario y URL de imagen si la hay
            DB::commit();
            return response()->json([
                'message' => 'Usuario creado correctamente',
                'user_id' => $user->id,
                'token' => $token,
                'image_url' => $image ? $image->url : null

            ], 201);
        } catch (\Exception $e) {
            // En caso de error, revertimos la transacción y devolvemos mensaje con error
            DB::rollback();
            return response()->json(['message' => 'No se pudo registrar el usuario', "error" => $e->getMessage()], 500);
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
                return response()->json(['message' => 'Credenciales invalidas'], 401);
            }

            return response()->json(['token' => $token], 200);
        } catch (JWTException $exception) {
            return response()->json(['error' => 'no se pudo generar el token', $exception], 500);
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


    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            $forgetCookie = \Cookie::forget('refresh_token');

            return response()->json(['message' => 'Desconectado'], 200)->withCookie($forgetCookie);
        } catch (JWTException $exception) {
            return response()->json(['error' => 'No se pudo cerrar la sesión', 500]);
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
            // Renovamos el access token actual, usando el refresh token que viene automáticamente en la cookie HttpOnly.
            $newToken = auth()->refresh();

          
            // - Una nueva cookie llamada 'refresh_token' con el mismo valor (simplificado),

            return response()
               //->json(['token' => $newToken]) // El access token que el frontend debe usar en los headers Authorization
                ->cookie(
                    'refresh_token',    // Nombre de la cookie
                    $newToken,          // Valor del token renovado
                    180,                // Duración: 180 minutos = 3 horas
                    null,               // Path: null = usa path por defecto (/)
                    null,               // Dominio: null = actual
                    false,              // Secure: false en desarrollo (debe ser true en producción con HTTPS)
                    true,               // HttpOnly: true = JS no puede leer esta cookie → más seguro contra XSS
                    false,              // Raw: false (el valor se codifica correctamente)
                    'Strict'            // SameSite: Strict = la cookie no se envía con requests de otros dominios (previene CSRF)
                );
        } catch (JWTException $e) {
            
            // Si algo falla (token inválido o expirado), devolvemos error 401.
            return response()->json(['error' => 'Token inválido o expirado'], 401);
        }
    }
}
