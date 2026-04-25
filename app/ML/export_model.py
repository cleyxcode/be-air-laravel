import joblib
import json
import numpy as np
import os

# Path ke model
BASE_DIR = "/home/code/Documents/PlatformIO/Projects/iot/be/app/ML/model"
MODEL_PATH = os.path.join(BASE_DIR, "knn_model.pkl")
SCALER_PATH = os.path.join(BASE_DIR, "scaler.pkl")
EXPORT_PATH = os.path.join(BASE_DIR, "model_data.json")

def export_model():
    if not os.path.exists(MODEL_PATH) or not os.path.exists(SCALER_PATH):
        print("Model or Scaler not found.")
        return

    try:
        model = joblib.load(MODEL_PATH)
        scaler = joblib.load(SCALER_PATH)

        # KNN data
        # _fit_X adalah data training yang sudah di-fit
        # classes_ adalah label-labelnya
        # y adalah index label untuk tiap data training
        
        data = {
            "best_k": int(model.n_neighbors),
            "classes": model.classes_.tolist(),
            "X_train": model._fit_X.tolist(),
            "y_train": model._y.tolist(),
            "scaler": {
                "mean": scaler.mean_.tolist(),
                "scale": scaler.scale_.tolist()
            }
        }

        with open(EXPORT_PATH, "w") as f:
            json.dump(data, f)
        
        print(f"Model exported to {EXPORT_PATH}")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    export_model()
