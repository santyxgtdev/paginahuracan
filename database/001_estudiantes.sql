CREATE TABLE categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(40) NOT NULL UNIQUE,
  anio_nac_min SMALLINT NOT NULL,
  anio_nac_max SMALLINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE acudientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombres VARCHAR(80) NOT NULL,
  apellidos VARCHAR(80) NOT NULL,
  tipo_documento ENUM('CC','CE','PAS') NOT NULL,
  numero_documento VARCHAR(20) NOT NULL UNIQUE,
  telefono VARCHAR(20) NOT NULL,
  correo VARCHAR(120) NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE estudiantes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombres VARCHAR(80) NOT NULL,
  apellidos VARCHAR(80) NOT NULL,
  tipo_documento ENUM('RC','TI','CC','CE','PAS') NOT NULL,
  numero_documento VARCHAR(20) NOT NULL UNIQUE,
  fecha_nacimiento DATE NOT NULL,
  categoria_id INT NOT NULL,
  estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  autorizacion_datos_en DATETIME NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE estudiante_acudiente (
  estudiante_id INT NOT NULL,
  acudiente_id INT NOT NULL,
  parentesco VARCHAR(30) NOT NULL,
  es_principal TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (estudiante_id, acudiente_id),
  FOREIGN KEY (estudiante_id) REFERENCES estudiantes(id),
  FOREIGN KEY (acudiente_id) REFERENCES acudientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;