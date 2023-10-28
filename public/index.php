<?php

namespace Project;

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Project\Database;
use Slim\Project\Validator;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);
$router = $app->getRouteCollector()->getRouteParser();

$database = new Database();
$validator = new Validator();
$admins = [
    ['name' => 'admin', 'password' => hash('sha256', 'admin')]
];

$app->get('/', function ($request, $response) {
    $flash = $this->get('flash')->getMessages();
    $params = [
        'currentUser' => $_SESSION['user'] ?? null,
        'flash' => $flash
    ];
    return $this->get('renderer')->render($response, 'users/login.phtml', $params);
})->setName('login');

$app->post('/session', function ($request, $response) use ($admins, $router) {
    $userCredentials = $request->getParsedBodyParam('user');
    $isFormCompleted = !empty($userCredentials['name']) && !empty($userCredentials['password']);
    if ($isFormCompleted) {
        $isUserAuthorized = false;
        foreach ($admins as $admin) {
            if (
                $admin['name'] === $userCredentials['name'] &&
                $admin['password'] === hash('sha256', $userCredentials['password'])
            ) {
                $_SESSION['user'] = $admin;
                $isUserAuthorized = true;
                break;
            }
        }
        if (!$isUserAuthorized) {
            $this->get('flash')->addMessage('error', 'Wrong password or name');
            return $response->withRedirect($router->urlFor('login'), 302);
        }
    }
    return $response->withRedirect($router->urlFor('showUsers'), 302);
});

$app->delete('/session', function ($request, $response) use ($router) {
    $_SESSION = [];
    session_destroy();
    return $response->withRedirect($router->urlFor('login'), 302);
});

$app->get('/user404', function ($request, $response) {
    return $response->write('This user does not exist!');
})->setName('404');

$app->get('/users', function ($request, $response) use ($database, $router) {
    if (!array_key_exists('user', $_SESSION) || $_SESSION['user'] === null) {
        $this->get('flash')->addMessage('error', 'Not logged in!');
        return $response->withRedirect($router->urlFor('login'), 302);
    }
    $term = $request->getQueryParam('term') ?? '';
    $users = $database->loadUsers();
    $usersTotal = count($users);
    $callback = fn($user) => str_contains($user['nickname'], $term);
    $allFilteredUsers = array_filter($users, $callback);
    $messages = $this->get('flash')->getMessages();
    $page = $request->getQueryParam('page', 1);
    $per = 5;
    $offset = ($page - 1) * $per;
    $filteredUsers = array_slice($allFilteredUsers, $offset, $per);
    $params = [
        'filteredUsers' => $filteredUsers, 'term' => $term, 'flash' => $messages,
        'page' => $page, 'usersTotal' => $usersTotal
    ];
    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('showUsers');

$app->get('/users/new', function ($request, $response) use ($router) {
    if (!array_key_exists('user', $_SESSION) || $_SESSION['user'] === null) {
        $this->get('flash')->addMessage('error', 'Not logged in!');
        return $response->withRedirect($router->urlFor('login'), 302);
    }
    $params = [
        'user' => ['id' => '', 'nickname' => '', 'email' => ''],
        'errors' => []
    ];
    return $this->get('renderer')->render($response, "users/new.phtml", $params);
})->setName('createUser');

$app->post('/users', function ($request, $response) use ($database, $router, $validator) {
    $users = $database->loadUsers();
    $data = $request->getParsedBodyParam('data');
    $normalizedData['nickname'] = htmlspecialchars(trim($data['nickname']));
    $normalizedData['email'] = htmlspecialchars(strtolower(trim($data['email'])));
    $errors = $validator->validate($normalizedData, $users);
    if (count($errors) === 0) {
        $user = $normalizedData;
        $user['id'] = ($users[count($users) - 1]['id'] ?? 0) + 1;
        $users[] = $user;
        $database->saveUsers($users);
        $this->get('flash')->addMessage('success', 'User was added successfully');
        return $response->withRedirect($router->urlFor('showUsers'), 302);
    }
    $params = ['user' => $normalizedData, 'errors' => $errors];
    $errorResponse = $response->withStatus(422);
    return $this->get('renderer')->render($errorResponse, "users/new.phtml", $params);
})->setName('saveUser');

$app->patch('/users/{id}', function ($request, $response, array $args) use ($database, $router, $validator) {
    $id = $args['id'];
    $editableUser = $database->findUser($id);
    $users = $database->loadUsers();
    $data = $request->getParsedBodyParam('data');
    $normalizedData['nickname'] = htmlspecialchars(trim($data['nickname']));
    $normalizedData['email'] = htmlspecialchars(strtolower(trim($data['email'])));
    $normalizedData['id'] = $editableUser['id'];
    $usersWithoutEditableUser = $users;
    unset($usersWithoutEditableUser[array_search($editableUser, $users)]);
    $errors = $validator->validate($normalizedData, $usersWithoutEditableUser);
    if (count($errors) === 0) {
        $editableUserKey = array_search($editableUser, $users);
        $editableUser = $normalizedData;
        $users[$editableUserKey] = $editableUser;
        $database->saveUsers($users);
        $this->get('flash')->addMessage('success', 'User has been updated');
        return $response->withRedirect($router->urlFor('showUsers'), 302);
    }
    $params = ['user' => $editableUser,'errors' => $errors];
    $response = $response->withStatus(422);
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
});

$app->delete('/users/{id}', function ($request, $response, array $args) use ($database, $router) {
    $confirmation = $request->getParsedBodyParam('confirmation');
    if ($confirmation === 'yes') {
        $id = $args['id'];
        $deletableUser = $database->findUser($id);
        $users = $database->loadUsers();
        unset($users[array_search($deletableUser, $users)]);
        $database->saveUsers([...$users]);
        $this->get('flash')->addMessage('success', 'User has been deleted');
    }
    return $response->withRedirect($router->urlFor('showUsers'));
});

$app->get('/users/{id}', function ($request, $response, $args) use ($database, $router) {
    if (!array_key_exists('user', $_SESSION) || $_SESSION['user'] === null) {
        $this->get('flash')->addMessage('error', 'Not logged in!');
        return $response->withRedirect($router->urlFor('login'), 302);
    }
    $id = $args['id'];
    $user = $database->findUser($id);
    if ($user) {
        $params = ['user' => $user];
        return $this->get('renderer')->render($response, 'users/show.phtml', $params);
    }
    return $response->withRedirect($router->urlFor('404'), 302);
})->setName('showUser');

$app->get('/users/{id}/edit', function ($request, $response, array $args) use ($database, $router) {
    if (!array_key_exists('user', $_SESSION) || $_SESSION['user'] === null) {
        $this->get('flash')->addMessage('error', 'Not logged in!');
        return $response->withRedirect($router->urlFor('login'), 302);
    }
    $id = $args['id'];
    $user = $database->findUser($id);
    if ($user) {
        $params = ['user' => $user, 'errors' => []];
        return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
    }
    return $response->withRedirect($router->urlFor('404'), 302);
})->setName('editUser');

$app->run();