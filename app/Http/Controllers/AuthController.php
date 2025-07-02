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
        DB::beginTransaction();
        try {

            $validator =  Validator::make($request->All(), [
                'name' => 'required|string|min:2|max:10',
                'email' => 'required|string|min:10|max:75|unique:users',
                'password' => 'required|string|min:10|confirmed',
                'image' => 'nullable|image|max:2048',
            ]);
            
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }
             
            $user = User::create([
                'name' => $request->get('name'),
                'role' => 'user',
                'password' =>  bcrypt($request->get('password')),
                'email' => $request->get('email')
            ]);



            $image = null;
            if ($request->hasFile('image')) {
                $uploadedFileUrl = $this->cloudinary->uploadApi()->upload(
                    $request->file('image')->getRealPath(),
                    [
                        'folder' => 'usuarios/' . $user->id
                    ]
                );

                $image = new Image([
                    'public_id' => $uploadedFileUrl['public_id'],
                    'url' => $uploadedFileUrl['secure_url']
                ]);

                $user->images()->save($image);
            }

            DB::commit();
            return response()->json([
                'message' => 'Usuario creado correctamente',
                'user_id' => $user->id,
                'image_url' => $image ? $image->url : null
            ], 201);
        } catch (\Exception $e) {
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
            return response()->json(['message' => 'Desconectado'], 200);
        } catch (JWTException $exception) {
            return response()->json(['error' => 'No se pudo cerrar la sesión', 500]);
        }
    }

    public function userUpdate(Request $request)
    {
        DB::beginTransaction();

        try {

            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|min:2|max:100',
                'email' => 'sometimes|string|email|min:10|max:75|unique:users,email,' . $user->id,
                'password' => 'sometimes|string|min:10|confirmed',
                'image' => 'sometimes|image|max:2048',
            ]);

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

            $user->save();

            if ($request->hasFile('image')) {
                try {
                    $oldImage = optional($user->images->first());
                    if ($oldImage->public_id) {
                        $this->cloudinary->uploadApi()->destroy($oldImage->public_id);
                        $oldImage->delete();
                    }
                    $uploadedFileUrl = $this->cloudinary->uploadApi()->upload(
                        $request->file('image')->getRealPath(),
                        [
                            'folder' => 'usuarios/' . $user->id
                        ]
                    );
                    $image = new Image([
                        'public_id' => $uploadedFileUrl['public_id'],
                        'url' => $uploadedFileUrl['secure_url']
                    ]);
                    $user->images()->save($image);
                } catch (\Throwable $th) {
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
}
