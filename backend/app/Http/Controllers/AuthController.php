<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * 🔐 LOGIN COMPATIBLE CON EL FRONTEND EN REACT (ZUSTAND)
     */
    public function login(Request $request)
    {
        $usuario = $request->input('usuario');
        $contrasena = $request->input('contraseña'); 

        try {
            // 1. Buscar al usuario en la base de datos (Query Builder)
            $user = DB::table('usuarios')
                ->where('usuario', $usuario)
                ->where('activo', true)
                ->first();

            // 2. Si no existe el usuario
            if (!$user) {
                return response()->json(['message' => 'El usuario no existe o está inactivo'], 401);
            }

            // 3. ⚡ SOLUCIÓN AL CIFRADO: Convertimos el formato de Node ($2b$) al de PHP ($2y$) en memoria
            $hashCompatible = str_replace('$2b$', '$2y$', $user->contraseña);

            // Validamos usando la función nativa con el hash formateado
            if (!password_verify($contrasena, $hashCompatible)) {
                return response()->json(['message' => 'Contraseña incorrecta'], 401);
            }

            // 4. Crear el Token usando Sanctum de forma manual (Stateless de alto rendimiento)
            $tokenPlano = bin2hex(random_bytes(20));
            $tokenSanctum = $user->id_usuario . '|' . $tokenPlano;
            
            DB::table('personal_access_tokens')->insert([
                'tokenable_type' => 'App\Models\User', 
                'tokenable_id'   => $user->id_usuario,
                'name'           => 'TensoQuimia_Token',
                'token'          => hash('sha256', $tokenPlano),
                'abilities'      => json_encode([$user->rol]),
                'created_at'     => now(),
                'updated_at'     => now(),
                'expires_at'     => now()->addHours(12), 
            ]);

            // 5. Enviar respuesta estructurada EXACTAMENTE igual que tu Node.js
            return response()->json([
                'message' => 'Autenticación exitosa',
                'token'   => $tokenSanctum,
                'user'    => [
                    'id'     => $user->id_usuario,
                    'nombre' => $user->nombre,
                    'rol'    => $user->rol
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error interno del servidor', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🔓 LOGOUT: Revocar token activo
     */
    public function logout(Request $request)
    {
        try {
            $header = $request->header('Authorization');
            if ($header && preg_match('/Bearer\s(\d+\|(.+))/', $header, $matches)) {
                $tokenPlano = $matches[2];
                
                DB::table('personal_access_tokens')
                    ->where('token', hash('sha256', $tokenPlano))
                    ->delete();
            }

            return response()->json(['message' => 'Sesión cerrada exitosamente']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al cerrar sesión'], 500);
        }
    }
}