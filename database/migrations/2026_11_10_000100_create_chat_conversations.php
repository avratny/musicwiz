<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unterhaltungen mit dem Assistenten (Chat-Overlay): Verlauf für die API (Inhaltsblöcke wie gesendet/empfangen), Anzeige
 * (Nachrichten, Werkzeug-Schritte, Änderungsvorschläge), offene Freigaben, Verbrauch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $t) {
            $t->id();
            $t->string('title')->nullable();
            $t->json('messages');                    // API-Verlauf (role + content-Blöcke)
            $t->json('items');                       // Anzeige
            $t->string('status')->default('idle');   // idle | running | awaiting | failed
            $t->json('pending')->nullable();         // Werkzeug-Ergebnisse + Vorschläge, die auf Freigabe warten
            $t->json('usage')->nullable();           // Tokens und geschätzte Kosten
            $t->string('model')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
