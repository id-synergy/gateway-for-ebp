<?php

namespace App\Services\Ebp;

use Illuminate\Http\UploadedFile;

/**
 * Corps de requête multipart/form-data à relayer tel quel vers EBP.
 *
 * Marqueur de transport entre le proxy (qui reconstitue le multipart reçu de l'app)
 * et {@see EbpClient}, qui sait alors envoyer un vrai multipart (fichiers + champs)
 * plutôt qu'un corps JSON. Indispensable pour les uploads (ex. Import/CreateImport),
 * qu'EBP refuse en application/json.
 */
class MultipartBody
{
    /**
     * @param  array<string, UploadedFile|UploadedFile[]>  $files  Fichiers par nom de champ.
     * @param  array<string, mixed>  $fields  Champs texte par nom.
     */
    public function __construct(
        public array $files = [],
        public array $fields = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->files === [] && $this->fields === [];
    }
}
