param(
  [string]$ServiceName = "DMSAgent"
)

sc.exe stop $ServiceName
sc.exe delete $ServiceName
