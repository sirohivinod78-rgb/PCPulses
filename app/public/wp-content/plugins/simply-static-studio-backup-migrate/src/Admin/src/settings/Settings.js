import SettingsContextProvider from './context/Context';
import './settings.scss';
import BackupPage from './pages/BackupPage';

function Settings() {
	return (
		<SettingsContextProvider>
			<BackupPage />
		</SettingsContextProvider>
	);
}

export default Settings;
