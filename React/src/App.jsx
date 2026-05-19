import { Route, Routes } from 'react-router-dom';
import AdminPage from './pages/AdminPage.jsx';
import ForgotPasswordPage from './pages/ForgotPasswordPage.jsx';
import HomePage from './pages/HomePage.jsx';
import LoginPage from './pages/LoginPage.jsx';
import PanelPage from './pages/PanelPage.jsx';
import RegisterPage from './pages/RegisterPage.jsx';
import UserProfilePage from './pages/UserProfilePage.jsx';
import VerifyEmailPage from './pages/VerifyEmailPage.jsx';

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<HomePage />} />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/register/verify" element={<VerifyEmailPage />} />
      <Route path="/forgetpassword" element={<ForgotPasswordPage />} />
      <Route path="/panel" element={<PanelPage />} />
      <Route path="/admin" element={<AdminPage />} />
      <Route path="/users" element={<UserProfilePage />} />
      <Route path="*" element={<HomePage />} />
    </Routes>
  );
}
