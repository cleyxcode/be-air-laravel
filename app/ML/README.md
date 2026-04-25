# Integrasi Model KNN ke Laravel

Letakkan file model Anda di folder ini:

- `app/ML/model/knn_model.pkl`
- `app/ML/model/scaler.pkl`

Script `app/ML/predict.py` akan dipanggil oleh `ApiController` di Laravel untuk melakukan klasifikasi.

### Catatan Penggunaan Laravel Sail
Jika Anda menggunakan Laravel Sail, pastikan container `laravel.test` memiliki Python dan library yang dibutuhkan (`joblib`, `scikit-learn`, `numpy`).

Anda dapat memodifikasi `docker/8.x/Dockerfile` (atau versi yang Anda gunakan) untuk menginstall python:

```dockerfile
RUN apt-get update && apt-get install -y python3 python3-pip
RUN pip3 install joblib scikit-learn numpy
```
