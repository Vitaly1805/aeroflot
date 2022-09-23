<?php
namespace model;

use mysql_xdevapi\Exception;
use PDO;
use vendor\MbWords;

class Model
{
    private static $pdo;
    private $twig;
    private $mbWords;
    private $namePage;
    private $arrForCurrentPage = [];
    private $routers = [];
    private $userId = 0;
    private static $cityBeg = '';
    private static $cityEnd = '';
    private static $dateBeg = '';
    private static $dateEnd = '';
    private static $classes = '';
    private static $amountPeople = 0;
    private static $minPrice = 0;
    private static $maxPrice = 0;

    public function __construct()
    {
        self::setPDO();
        $this->mbWords = new MbWords();
    }

    private static function setPDO()
    {
        $constants = get_defined_constants();
        self::$pdo = new \PDO("mysql:host={$constants['HOST']};dbname={$constants['DB']}", USER, PASSWORD, array(
            PDO::ATTR_PERSISTENT => true
        ));
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    public function setTwig($nameDirectory = '')
    {
        $loader = new \Twig\Loader\FilesystemLoader($nameDirectory);
        $this->twig = new \Twig\Environment($loader);
    }

    public function getTwig()
    {
        return $this->twig;
    }

    public function setRouters($arr)
    {
        $this->routers = $arr;
    }

    public function setNamePage($namePage = '')
    {
        $fl = true;
        $query = strtok(trim($_SERVER['REQUEST_URI'], '/'), '?');
        $arrRouters = $this->routers;

        if(!$query)
        {
            $this->namePage = 'aeroflot';
            $fl = false;
        }
        else
        {
            foreach ($arrRouters as $route) {
                if($query === $route)
                {
                    $this->namePage = $route;
                    $fl = false;
                }
            }
        }

        if($fl)
            $this->namePage = '404';

        if(!$this->checkCorrectPage())
            $this->namePage = '404';
    }

    public function getNamePage()
    {
        return $this->namePage;
    }

    public function setArrForCurrentPage()
    {
        $this->arrForCurrentPage['person'] = $this->getArrPerson();
        $this->arrForCurrentPage['nowTime'] = date('Y-m-d');
        $this->arrForCurrentPage['session'] = $_SESSION;

        $this->setUserId();
        $idUser = $this->getUserId();

        if($idUser > 0)
            $this->arrForCurrentPage['idUser'] = $idUser;

        $namePage = $this->getNamePage();

        if($namePage === 'aeroflot')
            $this->setVarsForPageAeroflot();
        elseif($namePage === 'tickets')
            $this->setVarsForPageTickets();
        elseif($namePage === 'user')
            $this->setVarsForPageUser();
        elseif($namePage === 'form')
            $this->setVarsForForm();
    }

    public function getArrForCurrentPage():array
    {
        return $this->arrForCurrentPage;
    }

    public function authorizationOnTheSite()
    {
//        $check_array = array('authorization', 'registration');
//        !array_diff($check_array, array_keys($_POST));

        if(isset($_SESSION['errorAuthorization']))
            $this->setVarsForAuthorization();

        if(isset($_SESSION['errorRegistration']))
            $this->setVarsForRegistration();

        if (isset($_POST['authorization'])) {
            $login = trim(htmlspecialchars($_POST['login']));
            $password = trim(htmlspecialchars($_POST['password']));
            $this->authorization($login, $password);
        }

        if(isset($_POST['registration']))
            $this->registration();
    }

    private function checkCorrectPage():bool
    {
        $namePage = $this->getNamePage();

        if(!empty($_SERVER['HTTP_REFERER']) && stripos($_SERVER['HTTP_REFERER'], 'form'))
            return true;

        if($namePage === 'form') {
            if(!$this->checkFlight())
                return false;
        }

        if($namePage === 'user') {
            if(!isset($_COOKIE['user']))
                return false;
        }

        return true;
    }

    private function checkFlight():bool
    {
        if(!isset($_COOKIE['user']) || !isset($_SESSION['adults']))
            return false;

        if(isset($_GET['flight'])) {
            $id = intval($_GET['flight']);
            $amountAdults = intval($_SESSION['adults']);
            $amountChildren = intval($_SESSION['children']);
            $class = $_SESSION['class'];

            $query = "CALL checkFlight($id, $amountAdults, $amountChildren, '$class')";
            $res = self::$pdo->query($query);

            if($res->fetch(PDO::FETCH_ASSOC)['amount'] == 1)
                return true;
        }

        return false;
    }

    private function setVarsForAuthorization()
    {
        $namesVars = ['errorAuthorization', 'login', 'password'];
        list($errorAuthorization, $login, $password) = $this->getVarsFromSessions($namesVars);
        $this->unsetSessions($namesVars);
        $this->setVarsForCurrentPage($namesVars, [$errorAuthorization, $login, $password]);
    }

    private function setVarsForForm()
    {
        if(!isset($_COOKIE['user']))
            return;

        $amountAdults = intval($_SESSION['adults']);
        $amountChildren = intval($_SESSION['children']);
        $id = intval($_COOKIE['user']);

        for($i = 0; $i < $amountAdults; $i++)
            $this->arrForCurrentPage['arrForms'][]['ageCategory'] = 'Взрослый';

        for($i = 0; $i < $amountChildren; $i++)
            $this->arrForCurrentPage['arrForms'][]['ageCategory']  = 'Детский';

        if(isset($_POST['order'])) {
            if($this->checkFlight()) {
                $this->setOrders();
                header('location: http://aeroflot/');
                die();
            }

            $this->setErrorOrder('Количество свободных мест стало недостаточным для такого количества человек!');
            $this->saveInfoAboutRemainingUsers();
        }

        if(isset($_SESSION['errorOrder']))
            $this->setErrorAndInfoIntoArrForCurrentPage();

        $this->arrForCurrentPage['infoAboutUser'] = $this->getInfoAboutUserAtId($id);
        $this->arrForCurrentPage['documents'] = $this->getDocuments();
        $this->arrForCurrentPage['idUser'] = $this->getUserId();
   }

   private function setErrorAndInfoIntoArrForCurrentPage()
   {
       $this->arrForCurrentPage['errorOrder'] = $_SESSION['errorOrder'];
       unset($_SESSION['errorOrder']);

       if(isset($_SESSION['infoAboutRemainingUsers'])) {
           $this->arrForCurrentPage['infoAboutRemainingUsers'] = $_SESSION['infoAboutRemainingUsers'];
           unset($_SESSION['infoAboutRemainingUsers']);
       }
   }

   private function setOrders()
   {
       $idFlight = intval($_GET['flight']);
       $idParent = intval($_COOKIE['user']);
       $class = $_SESSION['class'];

       for($i = 1; $i < 100; $i++) {
           if(!isset($_POST['name' . $i]))
               break;

           $arrNameVarsPost = ["surname$i", "name$i", "patronymic$i", "date_birthday$i", "gender$i", "document$i", "num_document$i"];
           list($surname, $name, $patronymic, $dateBirthday, $gender, $document, $numDocument) = $this->getVarsPost($arrNameVarsPost);

           $isset = $this->checkInformationAboutPassengersForOrder($surname, $name, $patronymic, $dateBirthday, $gender, $document, $numDocument, $idFlight);

           if(!$isset) {
               if(!$this->checkIssetUnregisteredPassenger($surname, $name, $patronymic, $dateBirthday, $gender, $document, $numDocument)) {
                   $ageCategory = $this->arrForCurrentPage['arrForms'][$i - 1]['ageCategory'];

                   $this->addUnregisteredPassenger($surname, $name, $patronymic, $dateBirthday, $gender, $ageCategory, $document, $numDocument, $idParent);
               }

               $idPassenger = $this->getIdUnregisteredPassengerAtNumDocument($numDocument);

               $this->setOrderForUnregisteredPassenger($idPassenger, $idFlight, $class);
           }
           else {
               $idPassenger = intval($_COOKIE['user']);
               $this->setOrderForPassenger($idPassenger, $idFlight, $class);
           }
       }
   }

   private function checkInformationAboutPassengersForOrder($surname, $name, $patronymic, $dateBirthday, $gender, $document, $numDocument, $idFlight):bool
   {
       if($this->checkIssetNumDocumentOrder($numDocument, $idFlight)) {
           $this->setErrorOrder('Данный номер документа уже зарегестрирован на рейс!');
           $this->saveInfoAboutRemainingUsers();
       }

       $isset = $this->checkIssetPassenger($surname, $name, $patronymic, $dateBirthday, $gender, $document, $numDocument);

       if($isset) {
           if($this->checkIssetNumDocumentPassenger($numDocument)) {
               $this->setErrorOrder('Данный номер документа уже используется (проверьте правильность ввода данных)');
               $this->saveInfoAboutRemainingUsers();
           }
       }

       $isset = $this->checkIssetUnregisteredPassenger($surname, $name, $patronymic, $dateBirthday, $gender, $document, $numDocument);

       if($isset) {
           if($this->checkIssetNumDocumentUnregisteredPassenger($numDocument)) {
               $this->setErrorOrder('Данный номер документа уже используется (проверьте правильность ввода данных)');
               $this->saveInfoAboutRemainingUsers();
           }
       }

       if(!$isset)
           return false;

       return true;
   }

   private function checkIssetNumDocumentOrder(string $numDocument = '', int $idFlight = 0):bool
   {
       if($numDocument === '' || $idFlight === 0)
           return false;

       if($this->checkIssetNumDocumentOrderForPassenger($numDocument, $idFlight))
            return true;
       else {
           if($this->checkIssetNumDocumentOrderForUnregisteredPassenger($numDocument, $idFlight))
               return true;
       }
            return false;
   }

    private function checkIssetNumDocumentOrderForUnregisteredPassenger(string $numDocument = '', int $idFlight = 0):bool
    {
        $query = "CALL checkIssetNumDocumentOrderForUnregisteredPassenger('$numDocument', $idFlight)";
        $res = self::$pdo->query($query);

        if($res->fetch(PDO::FETCH_ASSOC)['isset_unregistered_passenger_order'] == 1)
            return true;

        return false;
    }

   private function checkIssetNumDocumentOrderForPassenger(string $numDocument = '', int $idFlight = 0):bool
   {
       $query = "CALL checkIssetNumDocumentOrderForPassenger('$numDocument', $idFlight)";
       $res = self::$pdo->query($query);

       if($res->fetch(PDO::FETCH_ASSOC)['isset_passenger_order'] == 1)
           return true;

       return false;
   }

   private function getIdUnregisteredPassengerAtNumDocument(string $numDocument = ''):int
   {
       if($numDocument === '')
           return 0;

       $query = "CALL getIdUnregisteredPassengerAtNumDocument('$numDocument')";
       $res = self::$pdo->query($query);

       return intval($res->fetch(PDO::FETCH_ASSOC)['id_unregistered_passenger']);
   }

    private function setOrderForUnregisteredPassenger(int $idUnregisteredPassenger = 0, int $idFlight = 0, string $class = '')
    {
        if($idUnregisteredPassenger === 0 || $idFlight === 0 || $class === '')
            return;

        $query = "CALL setOrderForUnregisteredPassenger($idUnregisteredPassenger, $idFlight, '$class')";
        self::$pdo->query($query);
    }

    private function setOrderForPassenger(int $idPassenger = 0, int $idFlight = 0, string $class = '')
    {
        if($idPassenger === 0 || $idFlight === 0 || $class === '')
            return;

        $query = "CALL setOrderForPassenger($idPassenger, $idFlight, '$class')";
        self::$pdo->query($query);
    }

    private function checkIssetNumDocumentUnregisteredPassenger(string $numDocument):bool
    {
        $query = "CALL checkIssetNumDocumentUnregisteredPassenger('$numDocument')";
        $res = self::$pdo->query($query);

        if ($res->fetch(PDO::FETCH_ASSOC))
            return true;

        return false;
    }


    private function checkIssetNumDocumentPassenger(string $numDocument):bool
   {
       $query = "CALL checkIssetNumDocumentPassenger('$numDocument')";
       $res = self::$pdo->query($query);

       if ($res->fetch(PDO::FETCH_ASSOC))
           return true;

       return false;
   }

   private function checkIssetUnregisteredPassenger(string $surname, string $name, string $patronymic, string $dateBirthday, string $gender, string $document, string $numDocument):bool
   {
       $query = "CALL checkIssetUnregisteredPassenger('$surname', '$name', '$patronymic', '$dateBirthday', '$gender', '$document', '$numDocument')";
       $res = self::$pdo->query($query);
       $num =  $res->fetch(PDO::FETCH_ASSOC)['isset_user'];

       if($num == 1)
           return true;
       else
           return false;
   }

   private function checkIssetPassenger(string $surname, string $name, string $patronymic, string $dateBirthday, string $gender, string $document, string $numDocument):bool
   {
       $query = "CALL checkIssetPassenger('$surname', '$name', '$patronymic', '$dateBirthday', '$gender', '$document', '$numDocument')";
       $res = self::$pdo->query($query);
       $num = $res->fetch(PDO::FETCH_ASSOC)['isset_user'];

       if($num == 1)
           return true;
       else
           return false;
   }

   private function addUnregisteredPassenger(string $surname, string $name, string $patronymic, string $dateBirthday, string $gender, string $ageCategory, string $document, string $numDocument, int $idParent)
   {
       $query = "CALL addUnregisteredPassenger('$surname', '$name', '$patronymic', '$dateBirthday', '$gender', '$document', '$numDocument', $idParent, '$ageCategory')";
       self::$pdo->query($query);
   }

   private function getVarsPost(array $arr = []):array
   {
       $result = [];

       for ($i = 0; $i < count($arr); $i++) {
           if(isset($_POST[$arr[$i]]))
               $result[] = htmlspecialchars(trim($_POST[$arr[$i]]));
           else
               $result[] = '';
       }

       return $result;
   }

   private function saveInfoAboutRemainingUsers()
   {
       if($this->checkCurrentUserOrder())
           $num = 2;
       else
           $num = 1;

       for($i = $num; $i < 100; $i++) {
           if(!isset($_POST['name' . $i]))
               break;

           $_SESSION['infoAboutRemainingUsers'][$i]['surname'] = $_POST['surname' . $i];
           $_SESSION['infoAboutRemainingUsers'][$i]['name'] = $_POST['name' . $i];
           $_SESSION['infoAboutRemainingUsers'][$i]['patronymic'] = $_POST['patronymic' . $i];
           $_SESSION['infoAboutRemainingUsers'][$i]['date_birthday'] = $_POST['date_birthday' . $i];
           $_SESSION['infoAboutRemainingUsers'][$i]['gender'] = $_POST['gender' . $i];
           $_SESSION['infoAboutRemainingUsers'][$i]['document'] = $_POST['document' . $i];
           $_SESSION['infoAboutRemainingUsers'][$i]['num_document'] = $_POST['num_document' . $i];
       }

       header('location: ' . $_SERVER['REQUEST_URI']);
       die();
   }

   private function checkCurrentUserOrder():bool
   {
       $idUser = $this->getUserId();
       $arrNameVarsPost = ["surname1", "name1", "patronymic1", "date_birthday1", "gender1", "document1", "num_document1"];
       list($surname, $name, $patronymic, $dateBirthday, $gender, $document, $numDocument) = $this->getVarsPost($arrNameVarsPost);

       $query = "CALL checkCurrentUserOrder($idUser, '$surname', '$name', '$patronymic', '$dateBirthday', '$gender', '$document', '$numDocument')";
       $res = self::$pdo->query($query);

       if($res->fetch(PDO::FETCH_ASSOC)['isset_user'] == 1)
           return true;
       else
           return false;
   }

//   private function checkIssetCurrentUserIntoOrderAtId():bool
//   {
//       $idUser = $this->getUserId();
//
//       $query = "CALL checkIssetCurrentUserIntoOrderAtId($idUser)";
//       $res = self::$pdo->query($query);
//
//       if($res->fetch(PDO::FETCH_ASSOC)['isset_id'] == 1)
//           return true;
//       else
//           return false;
//   }

   private function setErrorOrder(string $message = '')
   {
       $_SESSION['errorOrder'] = $message;
   }

   private function getInfoAboutUserAtId(int $id  = 0):array
   {
       $query = "CALL getInfoAboutUserAtId($id)";
       $res = self::$pdo->query($query);
       return $res->fetch(PDO::FETCH_ASSOC);
   }

   private function getDocuments()
   {
       $query = "SELECT * FROM getDocuments";
       $res = self::$pdo->query($query);
       return $res->fetchAll(PDO::FETCH_ASSOC);
   }

    private function setVarsForRegistration()
    {
        $namesVars = ['nameReg', 'surnameReg', 'patronymicReg', 'loginReg', 'passwordReg', 'errorRegistration'];
        list($name, $surname, $patronymic, $login, $password, $error) = $this->getVarsFromSessions($namesVars);
        $this->unsetSessions($namesVars);
        $this->setVarsForCurrentPage($namesVars, [$name, $surname, $patronymic, $login, $password, $error]);
    }

    private function authorization($login = '', $password = '')
    {
        $query = "CALL authorization('$login', '$password')";
        $res = self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        try {
            if(empty($arr))
                throw new \Exception();

            $this->setUserCookie($arr);
        }
        catch (\Exception $e) {
            $_SESSION['errorAuthorization'] = 'Введены неверные данные!';
            $_SESSION['login'] = $_POST['login'];
            $_SESSION['password'] = $_POST['password'];
            header('location: ' . $_SERVER['REQUEST_URI']);
            die();
        }
    }

    private function setUserCookie($arr)
    {
        $nameCookie = 'user';
        $value = $arr['id_passenger'];

        if(isset($_POST['meRemember']))
            setcookie($nameCookie, $value, time() + (365 * 24 * 60 * 60* 100));
        else
            setcookie($nameCookie, $value, time() + (60 * 60));

        header('location: ' . $_SERVER['REQUEST_URI']);
        die();
    }

    private function registration()
    {
        $name       = trim($_POST['regName']);
        $surname    = trim($_POST['regName']);
        $patronymic = trim($_POST['regPatronymic']);
        $login      = trim($_POST['regLogin']);
        $password   = trim($_POST['password1']);

        if($this->checkVarsForRegistration($login)) {
            $str = 'Данный логин уже зарегестрирован!';
            $this->saveVarsToSession(['nameReg', 'surnameReg', 'patronymicReg', 'loginReg', 'passwordReg', 'errorRegistration'], [$name, $surname, $patronymic, $login, $password, $str]);
        }
        else {
            $query = "CALL registration('$name', '$surname', '$patronymic', '$login', '$password')";
            self::$pdo->query($query);
            $this->authorization($login, $password);
        }

        header("Location: {$_SERVER['REQUEST_URI']}");
        exit();
    }

    private function saveVarsToSession(array $arrNames, array $arrVars)
    {
        if(count($arrNames) !== count($arrVars))
            return;

        for($i = 0; $i < count($arrNames); $i++)
            $_SESSION[$arrNames[$i]] = $arrVars[$i];
    }

    private function getVarsFromSessions(array $namesVars = []):array
    {
        $result = [];

        for($i = 0; $i < count($namesVars); $i++) {
            if(!isset($_SESSION[$namesVars[$i]]))
                $result[] = '';

            $result[] = $_SESSION[$namesVars[$i]];
        }

        return $result;
    }

    private function unsetSessions(array $namesSessions = [])
    {
        for($i = 0; $i < count($namesSessions); $i++) {
            if(!isset($_SESSION[$namesSessions[$i]]))
                continue;

            unset($_SESSION[$namesSessions[$i]]);
        }
    }

    private function setVarsForCurrentPage(array $namesVars = [], array $values = [])
    {
        if(count($namesVars) !== count($values))
            return;

        for($i = 0; $i < count($namesVars); $i++)
            $this->arrForCurrentPage[$namesVars[$i]] = $values[$i];
    }

    private function checkVarsForRegistration($login):bool
    {
        $query = "CALL getInfoUserAtLogin('$login')";
        $res = self::$pdo->query($query);
        $fl = $res->fetch(PDO::FETCH_ASSOC);

        if(!$fl)
            return false;
        else
            return true;
    }

    private function getArrPerson():string
    {
        if(isset($_COOKIE['user']))
            return 'user';
        elseif(isset($_COOKIE['admin']))
            return 'admin';
        else
            return 'guest';
    }

    private function setVarsForPageAeroflot()
    {
        $this->arrForCurrentPage['classes'] = $this->getArrClasses();
    }

    private function setVarsForPageUser()
    {
        if(!isset($_COOKIE['user']))
            return;

        $this->setUserId();
        $userId = $this->getUserId();

        if(isset($_POST['updateInfoAboutUser']))
            $this->updateInfoAboutUser();

        if(isset($_POST['updatePasswordUser']))
            $this->updatePasswordUser();

        if(isset($_SESSION['messageForUpdatePassword']))
            $this->setMessageForUpdatePassword();

        if(isset($_SESSION['messageForUpdateInfoAboutUser']))
            $this->setMessageForUpdateInfoAboutUser();

        if(isset($_POST['exitIntoProfile']))
            $this->exitIntoProfile();

        $arrDocuments = $this->getArrDocuments();

        if(!empty($arrDocuments))
            $this->arrForCurrentPage['arrDocuments'] = $arrDocuments;

        $this->arrInfoAboutUserOrder();
        $this->setArrInfoAboutUser($userId);
    }

    private function setUserId()
    {
        if(!isset($_COOKIE['user']))
            $this->userId = 0;
        else
            $this->userId = intval($_COOKIE['user']);
    }

    private function getUserId():int
    {
        return $this->userId;
    }

    private function getArrDocuments():array
    {
        $arr = [];
        $query = "SELECT * FROM getDocuments";
        $res = self::$pdo->query($query);

        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $arr[] = $row;
        }

        return $arr;
    }

    private function updateInfoAboutUser()
    {
        $surnameUser      = trim(htmlspecialchars($_POST['surnameUser']));
        $nameUser         = trim(htmlspecialchars($_POST['nameUser']));
        $patronymicUser   = trim(htmlspecialchars($_POST['patronymicUser']));
        $dateBirthdayUser = trim(htmlspecialchars($_POST['dateBirthdayUser']));
        $genderNameUser   = trim(htmlspecialchars($_POST['genderNameUser']));
        $documentNameUser = trim(htmlspecialchars($_POST['documentNameUser']));
        $numDocumentUser  = trim(htmlspecialchars($_POST['numDocumentUser']));
        $loginUser        = trim(htmlspecialchars($_POST['loginUser']));
        $userId           = $this->getUserId();

        try {
            $query = "CALL updateInfoAboutUser('$surnameUser', '$nameUser', '$patronymicUser', '$dateBirthdayUser', '$genderNameUser', '$documentNameUser', '$numDocumentUser', '$loginUser', $userId)";
            $res = self::$pdo->query($query);
            $count = $res->rowCount();

            if($count === 0)
                throw new \Exception('Данные не обновлены!');

            $_SESSION['messageForUpdateInfoAboutUser'] = 'Данные успешно обновлены!';
        }
        catch (\Exception $e) {
            $_SESSION['messageForUpdateInfoAboutUser'] = $e->getMessage();
        }

        header("Location: http://aeroflot/user");
        exit;
    }

    private function updatePasswordUser()
    {
        $password = trim($_POST['secondPassword']);
        $userId = $this->userId;

        $query = "CALL updatePasswordUser($userId, '$password')";
        $res = self::$pdo->query($query);
        $count = $res->rowCount();

        try {
            if($count === 0)
                throw new \Exception('Пароль не сохранен!');

            $_SESSION['messageForUpdatePassword'] = 'Пароль успешно изменен!';
        }
        catch (\Exception $e){
            $_SESSION['messageForUpdatePassword'] = $e->getMessage();
        }

        header("Location: http://aeroflot/user");
        exit;
    }

    private function exitIntoProfile():void
    {
        setcookie('user', null, -1);

        header("Location: http://aeroflot/");
        exit;
    }

    private function arrInfoAboutUserOrder()
    {
        $userId = $this->userId;
        $query = "CALL getInfoAboutUserOrder($userId)";
        $res = self::$pdo->query($query);
        $arr = $res->fetchAll(PDO::FETCH_ASSOC);

        if(empty($arr[0]['surname']))
            $this->arrForCurrentPage['infoAboutUserOrder'] = 'Список заказов пуст';
        else
            $this->arrForCurrentPage['infoAboutUserOrder'] = $arr;
    }

    private function setMessageForUpdateInfoAboutUser()
    {
        $this->arrForCurrentPage['messageForUpdateInfoAboutUser'] = $_SESSION['messageForUpdateInfoAboutUser'];
        unset($_SESSION['messageForUpdateInfoAboutUser']);
    }

    private function setMessageForUpdatePassword()
    {
        $this->arrForCurrentPage['messageForUpdatePassword'] = $_SESSION['messageForUpdatePassword'];
        unset($_SESSION['messageForUpdatePassword']);
    }

    private function setArrInfoAboutUser($userId)
    {
        $query = "CALL searchUser($userId)";
        $res = self::$pdo->query($query);
        $this->arrForCurrentPage['infoAboutUser'] = $res->fetch(PDO::FETCH_ASSOC);
    }

    private function setVarsForPageTickets()
    {
        $this->arrForCurrentPage['classes'] = $this->getArrClasses();

        if(isset($_POST['ticket__button']))
            $this->setPostToSession(['ticketCityBeg', 'ticketCityEnd', 'ticketDateBeg', 'ticketDateEnd', 'class', 'adults', 'children']);

        $this->setVarsForSearch();
        $this->arrForCurrentPage['searchResult'] = $this->getArrSearchResult();
        $this->arrForCurrentPage['searchResultBack'] = $this->getArrSearchResult(true);
        $this->arrForCurrentPage['airplanes'] = $this->getArrTypeAirplanes();
        $this->arrForCurrentPage['minPrice'] = $this->getMinPrice();
        $this->arrForCurrentPage['maxPrice'] = $this->getMaxPrice();
        $this->arrForCurrentPage['minHoursBeg'] = $this->getMinHoursBeg();
        $this->arrForCurrentPage['minHoursEnd'] = $this->getMinHoursEnd();
        $this->arrForCurrentPage['maxHoursBeg'] = $this->getMaxHoursBeg();
        $this->arrForCurrentPage['maxHoursEnd'] = $this->getMaxHoursEnd();
        $this->arrForCurrentPage['minDateDeparture'] = $this->getMinDateDeparture();
        $this->arrForCurrentPage['maxDateDeparture'] = $this->getMaxDateDeparture();
        $this->arrForCurrentPage['maxDateDepartureBack'] = $this->getMaxDateDeparture(true);
        $this->arrForCurrentPage['minDateDepartureBack'] = $this->getMinDateDeparture(true);
        $this->arrForCurrentPage['minDateArrival'] = $this->getMinDateArrival();
        $this->arrForCurrentPage['maxDateArrival'] = $this->getMaxDateArrival();
        $this->arrForCurrentPage['minDateArrivalBack'] = $this->getMinDateArrival(true);
        $this->arrForCurrentPage['maxDateArrivalBack'] = $this->getMaxDateArrival(true);
        $this->arrForCurrentPage['arrAirportDeparture'] = $this->getArrAirportDeparture();
        $this->arrForCurrentPage['arrAirportArrival'] = $this->getArrAirportArrival();
        $this->arrForCurrentPage['arrAirportDepartureBack'] = $this->getArrAirportDeparture(true);
        $this->arrForCurrentPage['arrAirportArrivalBack'] = $this->getArrAirportArrival(true);
    }

    private function setPostToSession($arr)
    {
        foreach ($arr as $item) {
            if(isset($_POST[$item]))
                $_SESSION[$item] = $_POST[$item];
        }

        header("Location: http://aeroflot/tickets");
        exit;
    }

    private function getMinHoursBeg()
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        $query = "CALL getMinHours($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";
        $res = self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        if(isset($arr['minHours']))
            return $arr['minHours'];

        return [];
    }

    private function getMinHoursEnd()
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        $query = "CALL getMinHours($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
        $res = self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        if(isset($arr['minHours']))
            return $arr['minHours'];

        return [];
    }

    private function getMaxHoursBeg()
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        $query = "CALL getMaxHours($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";
        $res =self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        if(isset($arr['maxHours']))
            return $arr['maxHours'];

        return [];
    }

    private function getMaxHoursEnd()
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        $query = "CALL getMaxHours($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
        $res = self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        if(isset($arr['maxHours']))
            return $arr['maxHours'];

        return [];
    }

    private function getMinPrice()
    {
        $minPriceBeg = '';
        $minPriceEnd = '';

        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        $query = "CALL getMinPrice($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";
        $res = self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        if(isset($arr['price']))
            $minPriceBeg = $arr['price'];

        $res->closeCursor();

        $query = "CALL getMinPrice($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
        $res = self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        if(isset($arr['price']))
            $minPriceEnd = $arr['price'];

        if(!empty($minPriceBeg) && empty($minPriceEnd))
            return $minPriceBeg;
        elseif(empty($minPriceBeg) && !empty($minPriceEnd))
            return $minPriceEnd;
        elseif(!empty($minPriceBeg) && !empty($minPriceEnd))
        {
            if($minPriceBeg < $minPriceEnd)
                return $minPriceBeg;
            else
                return $minPriceEnd;
        }
    }

    private function getMaxPrice()
    {
        $maxPriceBeg = '';
        $maxPriceEnd = '';

        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        $query = "CALL getMaxPrice($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";
        $res = self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        if(isset($arr['price']))
            $maxPriceBeg = $arr['price'];

        $res->closeCursor();

        $query = "CALL getMaxPrice($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
        $res = self::$pdo->query($query);
        $arr = $res->fetch(PDO::FETCH_ASSOC);

        if(isset($arr['price']))
            $maxPriceEnd = $arr['price'];

        if(!empty($maxPriceBeg) && empty($maxPriceEnd))
            return $maxPriceBeg;
        elseif(empty($maxPriceBeg) && !empty($maxPriceEnd))
            return $maxPriceEnd;
        elseif(!empty($maxPriceBeg) && !empty($maxPriceEnd))
        {
            if($maxPriceBeg > $maxPriceEnd)
                return $maxPriceBeg;
            else
                return $maxPriceEnd;
        }
    }

    private static function getVarsForFilter() : array
    {
        $arr = [];

        $arr[] = self::getCityBeg();
        $arr[] = self::getCityEnd();
        $arr[] = self::getDateBeg();
        $arr[] = self::getDateEnd();
        $arr[] = self::getClasses();
        $arr[] = self::getAmountPeople();

        return $arr;
    }

    private function getArrTypeAirplanes()
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), trim($dateEnd, "'"), $amountPeople, $classes], true)) {
            $query = "CALL getAirplanes($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";
            $res = self::$pdo->query($query);
            $arrAirplaneBeg = $res->fetchAll(PDO::FETCH_ASSOC);

            $res->closeCursor();

            $query = "CALL getAirplanes($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
            $res = self::$pdo->query($query);
            $arrAirplaneEnd = $res->fetchAll(PDO::FETCH_ASSOC);

            $arr = $this->myArrMerge($arrAirplaneBeg, $arrAirplaneEnd, 'name');
            usort($arr, ['model\Model', 'sortArr']);

            return $arr;
        }

        return [];
    }

    private function myArrMerge($arrBeg = [], $arrEnd = [], $key = '')
    {
        if(empty($arrBeg) || empty($arrEnd))
            return [];

        $result = $arrBeg;

        for($i = 0; $i < count($arrEnd); $i++)
        {
            for($j = 0; $j < count($result); $j++)
            {
                if($arrEnd[$i][$key] === $result[$j][$key])
                    break;

                if($j + 1 === count($result))
                    $result[] = $arrEnd[$i];
            }
        }

        return $result;
    }

    private static function sortArr($a, $b)
    {
        return $a <=> $b;
    }

    private function getArrSearchResult($isBack = false)
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), trim($dateEnd, "'"), $amountPeople, $classes], true))
        {
            if($isBack)
                $query = "CALL searchFlights($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
            else
                $query = "CALL searchFlights($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";

            $res = self::$pdo->query($query);
            $result = $res->fetchAll(PDO::FETCH_ASSOC);
            $result[0]['cityBeg'] = mb_convert_case(trim($cityBeg, "'"), MB_CASE_TITLE, 'UTF-8');
            $result[0]['cityEnd'] = mb_convert_case(trim($cityEnd, "'"), MB_CASE_TITLE, 'UTF-8');

            return $result;
        }

        return [];
    }

    private function getMinDateDeparture($fl = false)
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), $amountPeople, $classes], true))
        {
            if($fl)
                $query = "CALL getMinDateDeparture($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
            else
                $query = "CALL getMinDateDeparture($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";

            $res = self::$pdo->query($query);
            $arr = $res->fetch(PDO::FETCH_ASSOC);

            if(isset($arr['date_departure']))
                return $arr['date_departure'];
        }

        return [];
    }

    private function getMaxDateDeparture($fl = false)
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), trim($dateEnd, "'"), $amountPeople, $classes], true))
        {
            if($fl)
                $query = "CALL getMaxDateDeparture($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
            else
                $query = "CALL getMaxDateDeparture($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";

            $res = self::$pdo->query($query);
            $arr = $res->fetch(PDO::FETCH_ASSOC);

            if(isset($arr['date_departure']))
                return $arr['date_departure'];
        }

        return [];
    }

    private function getMinDateArrival($fl = false)
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), $amountPeople, $classes], true))
        {
            if($fl)
                $query = "CALL getMinDateArrival($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
            else
                $query = "CALL getMinDateArrival($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";

            $res = self::$pdo->query($query);
            $arr = $res->fetch(PDO::FETCH_ASSOC);

            if(isset($arr['date_arrival']))
                return $arr['date_arrival'];
        }

        return [];
    }

    private function getMaxDateArrival($fl = false)
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), $amountPeople, $classes], true))
        {
            if($fl)
                $query = "CALL getMaxDateArrival($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
            else
                $query = "CALL getMaxDateArrival($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";

            $res = self::$pdo->query($query);
            $arr = $res->fetch(PDO::FETCH_ASSOC);

            if(isset($arr['date_arrival']))
                return $arr['date_arrival'];
        }

        return [];
    }

    private function getArrAirportDeparture($fl = false)
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), $amountPeople, $classes], true))
        {
            if($fl)
                $query = "CALL getArrAirportDeparture($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
            else
                $query = "CALL getArrAirportDeparture($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";

            $res = self::$pdo->query($query);
            $arr = $res->fetchAll(PDO::FETCH_ASSOC);

            if(isset($arr[0]['name']))
                return $arr;
        }

        return [];
    }

    private function getArrAirportArrival($fl = false)
    {
        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), $amountPeople, $classes], true))
        {
            if($fl)
                $query = "CALL getArrAirportArrival($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd)";
            else
                $query = "CALL getArrAirportArrival($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg)";

            $res = self::$pdo->query($query);
            $arr = $res->fetchAll(PDO::FETCH_ASSOC);

            if(isset($arr[0]['name']))
                return $arr;
        }

        return [];
    }

    private function setVarsForSearch()
    {
        self::setCityBeg();
        self::setCityEnd();
        self::setDateBeg();
        self::setDateEnd();
        self::setClasses();
        self::setClasses();
        self::setAmountPeople();
    }

    private static function setCityBeg()
    {
        if(isset($_POST['ticket__button']))
            self::$cityBeg = self::getPostVars('ticketCityBeg');
        elseif(isset($_SESSION['class']))
            self::$cityBeg = self::getSessionVars('ticketCityBeg');
    }

    private static function getCityBeg()
    {
        return self::$cityBeg;
    }

    private static function setCityEnd()
    {
        if(isset($_POST['ticket__button']))
            self::$cityEnd = self::getPostVars('ticketCityEnd');
        elseif(isset($_SESSION['class']))
            self::$cityEnd = self::getSessionVars('ticketCityEnd');
    }

    private static function getCityEnd()
    {
        return self::$cityEnd;
    }

    private static function setDateBeg()
    {
        if(isset($_POST['ticket__button']))
            self::$dateBeg = self::getPostVars('ticketDateBeg');
        elseif(isset($_SESSION['class']))
            self::$dateBeg = self::getSessionVars('ticketDateBeg');
    }

    private static function getDateBeg()
    {
        return self::$dateBeg;
    }

    private static function setDateEnd()
    {
        if(isset($_POST['ticket__button']))
            self::$dateEnd = self::getPostVars('ticketDateEnd');
        elseif(isset($_SESSION['class']))
            self::$dateEnd = self::getSessionVars('ticketDateEnd');
    }

    private static function getDateEnd()
    {
        return self::$dateEnd;
    }

    private static function setClasses()
    {
        if(isset($_POST['ticket__button']))
            self::$classes = self::getPostVars('class');
        elseif(isset($_SESSION['class']))
            self::$classes = self::getSessionVars('class');
    }

    private static function getClasses()
    {
        return self::$classes;
    }

    private static function setAmountPeople()
    {
        $adults = 0;
        $children = 0;

        if(isset($_POST['ticket__button']))
            list($adults, $children) = self::getPostVars(['adults', 'children'], true);
        elseif(isset($_SESSION['class']))
            list($adults, $children) = self::getSessionVars(['adults', 'children'], true);

        self::$amountPeople = $adults + $children;
    }

    private static function getAmountPeople()
    {
        return self::$amountPeople;
    }

    private static function isEmpty($arr, $fl = false)
    {
        foreach ($arr as $item) {
            if($fl === true)
            {
                if($item === '0')
                    continue;
            }

            if(empty($item))
                return true;
        }

        return false;
    }

    private function getArrClasses()
    {
        $query = 'SELECT * FROM getClasses';
        $res = self::$pdo->query($query);
        return $res->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function getPostVars($postKeys = '', $fl = false)
    {
        if(is_array($postKeys))
        {
            $result = [];

            foreach ($postKeys as $value)
            {
                if(!isset($_POST[$value]))
                    return $result;

                if($fl)
                    $result[] = trim(self::$pdo->quote($_POST[$value]), "'");
                else
                    $result[] = self::$pdo->quote($_POST[$value]);
            }
        }
        elseif(is_string($postKeys))
            if($fl)
                $result[] = intval(trim(self::$pdo->quote($_POST[$postKeys]), "'"));
            else
                $result = self::$pdo->quote($_POST[$postKeys]);
        else
            return '';

        return $result;
    }

    private static function getSessionVars($sessionKeys = [], $fl = false)
    {
        if(is_array($sessionKeys))
        {
            $result = [];

            foreach ($sessionKeys as $value)
            {
                if(!isset($_SESSION[$value]))
                    return $result;

                if($fl)
                    $result[] = intval(trim(self::$pdo->quote($_SESSION[$value]), "'"));
                else
                    $result[] = self::$pdo->quote($_SESSION[$value]);
            }
        }
        elseif(is_string($sessionKeys))
            if($fl)
                $result[] = trim(self::$pdo->quote($_SESSION[$sessionKeys]), "'");
            else
                $result = self::$pdo->quote($_SESSION[$sessionKeys]);
        else
            return '';

        return $result;
    }

    public static function setConfig()
    {
        self::setPDO();
        self::setCityBeg();
        self::setCityEnd();
        self::setClasses();
        self::setDateBeg();
        self::setDateEnd();
        self::setAmountPeople();
        self::setMaxPriceForFiltering();
        self::setMinPriceForFiltering();
    }

    public static function getArrFiltering(): array
    {
        $arr = [];
        $maxPrice = self::getMaxPriceForFiltering();
        $minPrice = self::getMinPriceForFiltering();

        list($cityBeg, $cityEnd, $dateBeg, $dateEnd, $classes, $amountPeople) = self::getVarsForFilter();

        if(!self::isEmpty([$cityBeg, $cityEnd, trim($dateBeg, "'"), trim($dateEnd, "'"), $amountPeople, $classes], true))
        {
            $arr =  self::getArrFilteringByPrice($cityBeg, $cityEnd, $dateBeg,$dateEnd, $amountPeople, $classes, $maxPrice, $minPrice);
            $arr =  self::getArrFilteringByAirplane($arr);
            $arr =  self::getArrFilteringByAirports($arr);
            $arr = self::getArrFilteringByTimeOfFlight($arr);
            return $arr;
        }

        return [];
    }

    public static function getStrForFiltering($arr, $key): string
    {
        if(empty($arr) || empty($key))
            return '';

        $str = '';
        $nameCityBeg = mb_convert_case(trim(self::getCityBeg(), "'"), MB_CASE_TITLE, 'UTF-8');
        $nameCityEnd = mb_convert_case(trim(self::getCityEnd(), "'"), MB_CASE_TITLE, 'UTF-8');

        if($key === 'Beg')
            $str .= $_POST['strFirstPartBeg'] . $nameCityBeg . ' - ' . $nameCityEnd . $_POST['strSecondPartBeg'];
        else
            $str .= $_POST['strFirstPartEnd'] . $nameCityEnd . ' - ' . $nameCityBeg . $_POST['strSecondPartEnd'];

        for($i = 0; $i < count($arr[$key]); $i++)
        {
            $str .= "  <div class='flights__block'>
                            <div class='flights__item'>
                                {$arr[$key][$i]['date_departure']}
                            </div>
                            <div class='flights__item'>
                                {$arr[$key][$i]['date_arrival']}
                            </div>
                            <div class='flights__item'>
                                {$arr[$key][$i]['airplaneName']}
                            </div>
                            <div class='flights__item'>
                                {$arr[$key][$i]['hours']} ч. {$arr[$key][$i]['minuts']} мин.
                            </div>
                            <div class='flights__item'>
                                от {$arr[$key][$i]['price']} <i class='icon-ruble'></i>
                            </div>
                            <div class='flights__item'>
                                <div class='flights-button'>
                                    <a href='form?flight={$arr[$key][$i]['id_flight']}' class='flights__choose'>Выбрать рейс</a>
                                </div>
                            </div>
                        </div>";
        }

        if($key === 'Beg')
            $str .= $_POST['strThirdPartBeg'];
        else
            $str .= $_POST['strThirdPartEnd'];

        return $str;
    }

    public static function getArrOut($str = '')
    {
        $result = [];

//        $result['nameCityBeg'] = mb_convert_case(trim(self::getCityBeg(), "'"), MB_CASE_TITLE, 'UTF-8');
//        $result['nameCityEnd']  = mb_convert_case(trim(self::getCityEnd(), "'"), MB_CASE_TITLE, 'UTF-8');

        $result['str'] = $str;

        return $result;
    }

    private static function getArrFilteringByTimeOfFlight($arr): array
    {
        $result = $arr;

        if(isset($_POST['hoursBegMin']) && isset($_POST['hoursBegMax']))
        {
            unset($result['Beg']);
            $hoursBegMin = intval($_POST['hoursBegMin']);
            $hoursBegMax = intval($_POST['hoursBegMax']);

            for($i = 0; $i < count($arr['Beg']); $i++)
            {
                $hours = intval($arr['Beg'][$i]['hours']);

                if($hours >= $hoursBegMin && $hours <= $hoursBegMax)
                    $result['Beg'][] = $arr['Beg'][$i];
            }
        }

        if(isset($_POST['hoursEndMin']) && isset($_POST['hoursEndMax']))
        {
            unset($result['End']);
            $hoursEndMin = intval($_POST['hoursEndMin']);
            $hoursEndMax = intval($_POST['hoursEndMax']);

            for($i = 0; $i < count($arr['End']); $i++)
            {
                $hours = intval($arr['End'][$i]['hours']);

                if($hours>= $hoursEndMin && $hours <= $hoursEndMax)
                    $result['End'][] = $arr['End'][$i];
            }
        }

        if(!isset($_POST['hoursBegMax']) && !isset($_POST['hoursBegMin']) && !isset($_POST['hoursEndMax']) && !isset($_POST['hoursEndMin']))
        {
            unset($result['End']);
            $hoursEndMin = 1;
            $hoursEndMax = 1;

            for($i = 0; $i < count($arr['End']); $i++)
            {
                $hours = intval($arr['End'][$i]['hours']);

                if($hours >= $hoursEndMin && $hours <= $hoursEndMax)
                {
                    $result['End'][] = $arr['End'][$i];
                }
            }
        }

        return $result;
    }

    private static function getArrFilteringByPrice($cityBeg, $cityEnd, $dateBeg,$dateEnd, $amountPeople, $classes, $maxPrice, $minPrice): array
    {
        $query = "CALL filteringByPrice($cityBeg, $cityEnd, $classes, $amountPeople, $dateBeg, $maxPrice, $minPrice)";
        $res = self::$pdo->query($query);
        $arr['Beg'] = $res->fetchAll(PDO::FETCH_ASSOC);

        $res->closeCursor();

        $query = "CALL filteringByPrice($cityEnd, $cityBeg, $classes, $amountPeople, $dateEnd, $maxPrice, $minPrice)";
        $res = self::$pdo->query($query);
        $arr['End'] = $res->fetchAll(PDO::FETCH_ASSOC);

        return $arr;
    }

    private static function getArrFilteringByAirplane($arr): array
    {
        $result = [];

        if(!isset($_POST['arrAirplanes']))
            $arrAirplanes= ['Airbus A320', 'Airbus A321'];
        else
            $arrAirplanes = $_POST['arrAirplanes'];

        for($i = 0; $i < count($arr['Beg']); $i++)
        {
            for($j = 0; $j < count($arrAirplanes); $j++)
            {
                if($arrAirplanes[$j] === $arr['Beg'][$i]['airplaneName'])
                    $result['Beg'][] =  $arr['Beg'][$i];
            }
        }

        for($i = 0; $i < count($arr['End']); $i++)
        {
            for($j = 0; $j < count($arrAirplanes); $j++)
            {
                if($arrAirplanes[$j] === $arr['End'][$i]['airplaneName'])
                    $result['End'][] =  $arr['End'][$i];
            }
        }

        return $result;
    }

    private static function getArrFilteringByAirports($arr): array
    {
        $result = [];

        if(isset($_POST['arrAirportsDepartureBeg']))
        {
            $result['End'] = $arr['End'];
            $arrAirportsDepartureBeg = $_POST['arrAirportsDepartureBeg'];
            $result['Beg'] = self::getArrFilteringByKey($arr, $arrAirportsDepartureBeg, 'Beg', 'airportName');
        }

        if(isset($_POST['arrAirportsArrivalBeg']))
        {
            $result['End'] = $arr['End'];
            $arrAirportsArrivalBeg = $_POST['arrAirportsArrivalBeg'];
            $result['Beg']  = self::getArrFilteringByKey($arr, $arrAirportsArrivalBeg, 'Beg', 'airportNameArrival');
        }

        if(isset($_POST['arrAirportsDepartureEnd']))
        {
            $result['Beg'] = $arr['Beg'];
            $arrAirportsDepartureEnd = $_POST['arrAirportsDepartureEnd'];
            $result['End'] = self::getArrFilteringByKey($arr, $arrAirportsDepartureEnd, 'End', 'airportName');
        }

        if(isset($_POST['arrAirportsArrivalEnd']))
        {
            $result['Beg'] = $arr['Beg'];
            $arrAirportsArrivalEnd = $_POST['arrAirportsArrivalEnd'];
            $result['End']  = self::getArrFilteringByKey($arr, $arrAirportsArrivalEnd, 'End', 'airportNameArrival');
        }

        if(!isset($_POST['arrAirportsDepartureBeg']) && !isset($_POST['arrAirportsArrivalBeg']) && !isset($_POST['arrAirportsDepartureEnd']) && !isset($_POST['arrAirportsArrivalEnd']))
            return $arr;

        return $result;
    }

    private static function isTheSameArrValue($arr, $key)
    {
        $counter = 1;

        for($i = 0; $i < count($arr); $i++)
        {
            if($i + 1 === count($arr))
            {
                if($counter === count($arr))
                    return true;
                else
                    return false;
            }

            if($arr[$i][$key] === $arr[$i+1][$key])
                $counter++;
        }

        return false;
    }

    private static function getArrFilteringByKey($arrStart, $arrFiltering, $typeArr, $key)
    {
        $result = [];

        for ($i = 0; $i < count($arrStart[$typeArr]); $i++) {
            for ($j = 0; $j < count($arrFiltering); $j++) {
                if ($arrFiltering[$j] === $arrStart[$typeArr][$i][$key])
                    $result[] = $arrStart[$typeArr][$i];
            }
        }

        return $result;
    }

    private static function setMaxPriceForFiltering()
    {
        if(isset($_POST['maxPrice']))
            self::$maxPrice= intval($_POST['maxPrice']);
        else
            self::$maxPrice= 10000;
    }

    private static function getMaxPriceForFiltering(): int
    {
        return self::$maxPrice;
    }

    private static function setMinPriceForFiltering()
    {
        if(isset($_POST['minPrice']))
            self::$minPrice= intval($_POST['minPrice']);
        else
            self::$minPrice= 0;
    }

    private static function getMinPriceForFiltering(): int
    {
        return self::$minPrice;
    }
}