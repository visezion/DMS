from datetime import datetime, timezone

from fastapi import FastAPI


app = FastAPI(title="DMS Agent Backend", version="1.0.0")


@app.get("/")
def root() -> dict[str, str]:
    return {"service": "dms-agent-backend", "status": "ok"}


@app.get("/health")
def health() -> dict[str, str]:
    return {
        "status": "ok",
        "checked_at": datetime.now(timezone.utc).isoformat(),
    }
