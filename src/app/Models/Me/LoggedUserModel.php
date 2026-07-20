<?php
namespace App\Consultant\app\Models\Me;

class LoggedUserModel {
    private ?int    $id;
    private ?int    $scope_id;
    private ?string $scope_type;
    private ?string $display_name;
    private ?string $lang_code;
    private array   $permissions;

    public function __construct(array $data)
    {
        $this->id           = isset($data['usr_id'])   ? (int)$data['usr_id']   : null;
        $this->scope_id     = isset($data['scope_id']) ? (int)$data['scope_id'] : null;
        $this->scope_type   = $data['scope_type'] ?? null;
        $this->display_name = $data['usr_dn']     ?? null;
        $this->lang_code    = $data['usr_ln']     ?? null;
        $this->permissions  = (array)($data['perms'] ?? []);
    }

    public function getId(): ?int            { return $this->id; }
    public function getScopeId(): ?int       { return $this->scope_id; }
    public function getScopeType(): ?string  { return $this->scope_type; }
    public function getDisplayName(): ?string { return $this->display_name; }
    public function getLanguageCode(): ?string { return $this->lang_code; }
    public function getPermissions(): array  { return $this->permissions; }
}

