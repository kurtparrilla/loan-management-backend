<?php
    class Database{
        private $host;
        private $db_name;
        private $username;
        private $password;
        private $conn = null;

        public function __construct(){
            $envPath = __DIR__ . '/../.env';
            if(file_exists($envPath)){
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach($lines as $line){
                    if(strpos(trim($line), '#') === 0) continue;
                    if(strpos($line, '=') !== false){
                        list($key, $value) = explode('=', $line, 2);
                        $key = trim($key);
                        $value = trim($value);
                        putenv("$key=$value");
                        $_ENV[$key] = $value;
                    }
                }
            }
            $this->host = getenv('DB_HOST') ?: 'localhost';
            $this->db_name = getenv('DB_NAME') ?: '';
            $this->username = getenv('DB_USERNAME') ?: '';
            $this->password = getenv('DB_PASSWORD') ?: '';
        }
        public function connect(){
            if($this->conn === null){
                try{
                    $this->conn = new PDO(
                        "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
                        $this->username,
                        $this->password
                    );

                }catch(PDOException $e){
                    die(json_encode([
                        'status'  => 'error',
                        'message' => 'Database connection failed: ' . $e->getMessage()
                    ]));
                }
            }
            return $this->conn;
        }   
    }

