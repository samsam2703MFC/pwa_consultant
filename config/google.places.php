<?php
// Place IDs Google des magasins (id_magasin → Place ID « ChIJ… »).
//
// Un Place ID est un identifiant PUBLIC (présent dans tout lien Google Maps),
// donc ce fichier est committé sans risque — contrairement à la CLÉ API qui,
// elle, reste dans config/google.local.php (hors Git).
//
// Priorité de résolution de la fiche (GoogleRatingRepository) :
//   place_ids de google.local.php > CE fichier > google_place_id du magasin
//   > google_address du magasin > nom + ville.
//
// Trouver le Place ID d'un magasin :
//   https://developers.google.com/maps/documentation/places/web-service/place-id
return [
    'place_ids' => [
        2 => 'ChIJPxlW5ut-wUcRr4JQ_JCcchY',   // Atelier by Berlo — Corbais
        3 => 'ChIJM9lpb6EvwkcRM2c-LVCbk_8',   // Atelier by Max & Sandra — Gosselies
        5 => 'ChIJTeMheYWBwUcRR6SSNZJuvvA',   // Atelier by Harmonie — Sombreffe
        // Halle : ChIJYfiSJtHJw0cRF9vyw1GdFMM — en attente de l'id du magasin
    ],
];
