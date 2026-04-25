import sys
import json
import joblib
import numpy as np
import os

# Path ke model
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(BASE_DIR, "model", "knn_model.pkl")
SCALER_PATH = os.path.join(BASE_DIR, "model", "scaler.pkl")

def classify(soil, temp, rh):
    if not os.path.exists(MODEL_PATH):
        return {
            "label": "Error",
            "confidence": 0,
            "needs_watering": False,
            "description": "Model file not found at " + MODEL_PATH
        }
    
    try:
        model = joblib.load(MODEL_PATH)
        scaler = joblib.load(SCALER_PATH)
        
        feat = scaler.transform(np.array([[soil, temp, rh]]))
        label = model.predict(feat)[0]
        proba = model.predict_proba(feat)[0]
        
        return {
            "label": label,
            "confidence": float(max(proba)) * 100,
            "needs_watering": label == "Kering",
            "description": "Prediction from Python model."
        }
    except Exception as e:
        return {"error": str(e)}

if __name__ == "__main__":
    if len(sys.argv) < 4:
        print(json.dumps({"error": "Usage: predict.py <soil> <temp> <rh>"}))
        sys.exit(1)
        
    soil = float(sys.argv[1])
    temp = float(sys.argv[2])
    rh = float(sys.argv[3])
    
    print(json.dumps(classify(soil, temp, rh)))
