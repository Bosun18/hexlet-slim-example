<?php

namespace Slim\Project;

class Database
{
    private mixed $users;
    private string $path = __DIR__ . "/../database.json";

    public function __construct()
    {
        $this->users = json_decode(base64_decode(file_get_contents($this->path)), true) ?? [];
    }

    public function loadUsers(): array
    {
        $this->users = json_decode(base64_decode(file_get_contents($this->path)), true) ?? [];
        return $this->users;
    }

    public function saveUsers(array $users): int|false
    {
        return file_put_contents($this->path, base64_encode(json_encode($users, JSON_PRETTY_PRINT)));
    }

    public function findUser(int $id): array|false
    {
        foreach ($this->users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        return false;
    }
}
