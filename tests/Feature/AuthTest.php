<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AuthTest extends TestCase
{
   use RefreshDatabase;

   public function test_login_with_valid_credentials(){

     $user = \App\Models\User::factory()->create([
        'email' => 'usuario_prueba_1234@example.com', 
        'password' => bcrypt('12345678910')
    ]);

    $response = $this-> postJson('api/login',[
        'email' => 'usuario_prueba_1234@example.com', 
        'password' => '12345678910',
    ]);

    
    $response->assertStatus(200)
             ->assertJsonStructure(['token']);
   }
}
