<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {

        $validator =  Validator::make($request->All(), [
            'name' => 'required|string|min:2|max:10',
            'email' => 'required|string|min:10|max:75|unique:users',
            'password' => 'required|string|min:10|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
        User::create([
            'name' => $request->get('name'),
            'role' => 'user',
            'password' =>  bcrypt($request->get('password')),
            'email' => $request->get('email')
        ]);
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
        return response()->json($user, 200);
    }

    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());
        return response()->json(['message' => 'logged out'], 200);
    }
}
