use serde::ser::SerializeStruct;
use serde::{Serialize, Serializer};

/// Fehler, die an die Oberfläche gehen. `code` ist ein Übersetzungsschlüssel
/// (siehe ui/locales/*.json), `detail` ein optionaler technischer Zusatz.
#[derive(Debug, thiserror::Error)]
pub enum AppError {
    #[error("{0}")]
    Code(&'static str),
    #[error("{code}: {detail}")]
    Detail { code: &'static str, detail: String },
}

pub type AppResult<T> = Result<T, AppError>;

pub fn err(code: &'static str) -> AppError {
    AppError::Code(code)
}

pub fn err_detail(code: &'static str, detail: impl ToString) -> AppError {
    AppError::Detail {
        code,
        detail: detail.to_string(),
    }
}

impl AppError {
    pub fn code(&self) -> &'static str {
        match self {
            AppError::Code(code) | AppError::Detail { code, .. } => code,
        }
    }
}

impl From<std::io::Error> for AppError {
    fn from(error: std::io::Error) -> Self {
        err_detail("err_io", error)
    }
}

impl From<serde_json::Error> for AppError {
    fn from(error: serde_json::Error) -> Self {
        err_detail("err_io", error)
    }
}

impl Serialize for AppError {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut state = serializer.serialize_struct("AppError", 2)?;
        state.serialize_field("code", self.code())?;
        match self {
            AppError::Code(_) => state.serialize_field("detail", &Option::<String>::None)?,
            AppError::Detail { detail, .. } => state.serialize_field("detail", detail)?,
        }
        state.end()
    }
}
