Option Explicit

Dim fso, shell, baseDir, scriptPath, logDir, logPath, pythonExe, command
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

baseDir = fso.GetParentFolderName(WScript.ScriptFullName)
scriptPath = fso.BuildPath(baseDir, "fingerprint_bridge.py")
logDir = fso.BuildPath(baseDir, "logs")
logPath = fso.BuildPath(logDir, "fingerprint_bridge.log")

If Not fso.FileExists(scriptPath) Then
    WriteLauncherError logPath, "fingerprint_bridge.py was not found in " & baseDir
    WScript.Quit 1
End If

If Not fso.FolderExists(logDir) Then
    On Error Resume Next
    fso.CreateFolder logDir
    If Err.Number <> 0 Then
        WriteLauncherError logPath, "Could not create the bridge log directory."
        WScript.Quit 1
    End If
    On Error GoTo 0
End If

shell.CurrentDirectory = baseDir
shell.Environment("Process")("DMD_IOT_LOG_FILE") = logPath

' DMD_IOT_PYTHONW_EXE may be set to a full pythonw.exe path on machines where
' Python is not available through the scheduled task's PATH.
pythonExe = shell.Environment("Process")("DMD_IOT_PYTHONW_EXE")
If Len(Trim(pythonExe)) = 0 Then pythonExe = "pythonw.exe"

command = Quote(pythonExe) & " " & Quote(scriptPath)
On Error Resume Next
shell.Run command, 0, True
If Err.Number <> 0 Then
    WriteLauncherError logPath, "Unable to start Python bridge: " & Err.Description
    WScript.Quit 1
End If
On Error GoTo 0

Function Quote(value)
    Quote = Chr(34) & value & Chr(34)
End Function

Sub WriteLauncherError(path, message)
    On Error Resume Next
    Dim file
    If fso Is Nothing Then Exit Sub
    Set file = fso.OpenTextFile(path, 8, True)
    file.WriteLine Now & " " & message
    file.Close
End Sub
