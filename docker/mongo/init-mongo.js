// Corre una sola vez, cuando el volumen de Mongo está vacío (primera vez que se levanta).
// Crea el usuario de la aplicación, separado del root, con permisos solo sobre la base de UASnack.
db = db.getSiblingDB(process.env.MONGO_INITDB_DATABASE);

db.createUser({
  user: process.env.MONGO_APP_USERNAME,
  pwd: process.env.MONGO_APP_PASSWORD,
  roles: [
    { role: 'readWrite', db: process.env.MONGO_INITDB_DATABASE }
  ]
});
