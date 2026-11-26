<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** El rol EST se llama simplemente "Estudiante" (antes "Estudiante FICCT") */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('codigo', 'EST')->where('nombre', 'Estudiante FICCT')->update(['nombre' => 'Estudiante', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('roles')->where('codigo', 'EST')->where('nombre', 'Estudiante')->update(['nombre' => 'Estudiante FICCT', 'updated_at' => now()]);
    }
};
